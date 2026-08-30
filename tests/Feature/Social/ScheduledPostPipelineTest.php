<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Jobs\DispatchScheduledPostsJob;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\OAuth\OAuthManager;
use App\Modules\Social\Services\SocialPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledPostPipelineTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
    }

    private function makeAccount(array $attrs = []): SocialAccount
    {
        return SocialAccount::create(array_merge([
            'workspace_id' => $this->ctx['workspace']->id,
            'network' => 'facebook',
            'account_id' => 'page_'.fake()->unique()->numerify('######'),
            'name' => 'Test Page',
            'access_token' => 'token-abc',
            'active' => true,
        ], $attrs));
    }

    private function makePost(array $accountIds, array $attrs = []): SocialPost
    {
        return SocialPost::create(array_merge([
            'workspace_id' => $this->ctx['workspace']->id,
            'body' => 'Hello world',
            'media_urls' => [],
            'target_accounts' => $accountIds,
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'timezone' => 'UTC',
        ], $attrs));
    }

    /* ───────────────────────── Dispatcher ───────────────────────── */

    public function test_due_scheduled_post_is_flipped_to_publishing_and_dispatched(): void
    {
        Queue::fake();
        $account = $this->makeAccount();
        $post = $this->makePost([$account->id]);

        (new DispatchScheduledPostsJob)->handle();

        $this->assertSame('publishing', $post->fresh()->status);
        Queue::assertPushedOn('social', PublishSocialPostJob::class, fn ($job) => $job->postId === $post->id);
    }

    public function test_future_scheduled_post_is_not_dispatched(): void
    {
        Queue::fake();
        $account = $this->makeAccount();
        $post = $this->makePost([$account->id], ['scheduled_at' => now()->addHour()]);

        (new DispatchScheduledPostsJob)->handle();

        $this->assertSame('scheduled', $post->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_post_stuck_in_publishing_is_requeued_after_30_minutes(): void
    {
        Queue::fake();
        $account = $this->makeAccount();
        $post = $this->makePost([$account->id], ['status' => 'publishing']);
        SocialPost::where('id', $post->id)->update(['updated_at' => now()->subMinutes(40)]);

        (new DispatchScheduledPostsJob)->handle();

        Queue::assertPushedOn('social', PublishSocialPostJob::class, fn ($job) => $job->postId === $post->id);
        // Staleness clock reset so the next tick doesn't re-dispatch again.
        $this->assertTrue($post->fresh()->updated_at->gt(now()->subMinutes(5)));
    }

    public function test_recently_updated_publishing_post_is_not_requeued(): void
    {
        Queue::fake();
        $account = $this->makeAccount();
        $this->makePost([$account->id], ['status' => 'publishing']);

        (new DispatchScheduledPostsJob)->handle();

        Queue::assertNothingPushed();
    }

    /* ───────────────────────── Publisher ───────────────────────── */

    public function test_successful_publish_marks_post_and_links_published(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'fb_post_1'])]);
        $account = $this->makeAccount();
        $post = $this->makePost([$account->id]);

        app(SocialPublisher::class)->publish($post, finalAttempt: false);

        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertNotNull($post->published_at);
        $this->assertSame('published', SocialPostAccount::where('post_id', $post->id)->first()->status);
    }

    public function test_transient_failure_with_retries_left_keeps_post_publishing_and_throws(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);
        $account = $this->makeAccount();
        $post = $this->makePost([$account->id]);

        $this->expectException(\RuntimeException::class);

        try {
            app(SocialPublisher::class)->publish($post, finalAttempt: false);
        } finally {
            $post->refresh();
            $this->assertSame('publishing', $post->status, 'Post must stay publishing so the job retry re-runs it.');
            $this->assertSame('failed', SocialPostAccount::where('post_id', $post->id)->first()->status);
        }
    }

    public function test_transient_failure_on_final_attempt_marks_post_failed(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);
        $account = $this->makeAccount();
        $post = $this->makePost([$account->id]);

        app(SocialPublisher::class)->publish($post, finalAttempt: true);

        $post->refresh();
        $this->assertSame('failed', $post->status);
        $this->assertNull($post->published_at);
    }

    public function test_retry_skips_accounts_already_published(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'fb_post_2'])]);
        $done = $this->makeAccount();
        $pending = $this->makeAccount();
        $post = $this->makePost([$done->id, $pending->id], ['status' => 'publishing']);
        SocialPostAccount::create([
            'post_id' => $post->id,
            'social_account_id' => $done->id,
            'status' => 'published',
            'platform_post_id' => 'fb_post_prev',
        ]);

        app(SocialPublisher::class)->publish($post, finalAttempt: true);

        $this->assertSame('published', $post->fresh()->status);
        // Only the pending account hit the API.
        Http::assertSentCount(1);
        $this->assertSame(
            'fb_post_prev',
            SocialPostAccount::where('post_id', $post->id)->where('social_account_id', $done->id)->first()->platform_post_id
        );
    }

    public function test_inactive_account_fails_permanently_without_burning_retries(): void
    {
        Http::fake();
        $account = $this->makeAccount(['active' => false]);
        $post = $this->makePost([$account->id]);

        // finalAttempt=false: a permanent failure must NOT throw for retry.
        app(SocialPublisher::class)->publish($post, finalAttempt: false);

        $post->refresh();
        $this->assertSame('failed', $post->status);
        $link = SocialPostAccount::where('post_id', $post->id)->first();
        $this->assertSame('failed', $link->status);
        $this->assertStringContainsString('Reconnect', $link->error);
        Http::assertNothingSent();
    }

    public function test_expired_meta_token_fails_permanently_with_clear_error(): void
    {
        Http::fake();
        $account = $this->makeAccount(['token_expires_at' => now()->subDay()]);
        $post = $this->makePost([$account->id]);

        app(SocialPublisher::class)->publish($post, finalAttempt: false);

        $link = SocialPostAccount::where('post_id', $post->id)->first();
        $this->assertSame('failed', $link->status);
        $this->assertStringContainsString('expired', $link->error);
        Http::assertNothingSent();
    }

    public function test_expired_refreshable_token_is_refreshed_inline_before_publish(): void
    {
        Http::fake(['api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 'tw_1']])]);
        $this->mock(OAuthManager::class, function ($mock) {
            $mock->shouldReceive('refresh')
                ->once()
                ->with('twitter', 'old-refresh')
                ->andReturn(['access_token' => 'fresh-token', 'expires_in' => 7200]);
        });

        $account = $this->makeAccount([
            'network' => 'twitter',
            'token_expires_at' => now()->subHour(),
            'refresh_token' => 'old-refresh',
        ]);
        $post = $this->makePost([$account->id]);

        app(SocialPublisher::class)->publish($post, finalAttempt: true);

        $account->refresh();
        $this->assertSame('fresh-token', $account->access_token);
        $this->assertSame('published', $post->fresh()->status);
    }

    /* ───────────────────────── Job lifecycle ───────────────────────── */

    public function test_job_failed_hook_marks_post_failed_instead_of_stuck_publishing(): void
    {
        $account = $this->makeAccount();
        $post = $this->makePost([$account->id], ['status' => 'publishing']);

        (new PublishSocialPostJob($post->id))->failed(new \RuntimeException('worker died'));

        $this->assertSame('failed', $post->fresh()->status);
    }

    public function test_job_skips_post_reverted_to_draft(): void
    {
        Http::fake();
        $account = $this->makeAccount();
        $post = $this->makePost([$account->id], ['status' => 'draft']);

        (new PublishSocialPostJob($post->id))->handle(app(SocialPublisher::class));

        $this->assertSame('draft', $post->fresh()->status);
        Http::assertNothingSent();
    }
}
