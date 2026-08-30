<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\SocialPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PublishSocialPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // Multi-image/video publishes make several sequential API calls; 120s was
    // short enough for the worker to kill legitimate runs mid-publish.
    public int $timeout = 300;

    public function __construct(public readonly int $postId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(SocialPublisher $publisher): void
    {
        $post = SocialPost::find($this->postId);

        // Post deleted, already fully published, or reverted to draft — nothing to do.
        if (! $post || in_array($post->status, ['published', 'draft'], true)) {
            return;
        }

        $publisher->publish($post, finalAttempt: $this->attempts() >= $this->tries);
    }

    /**
     * All retries exhausted (or the job timed out / crashed): never leave the
     * post stuck in 'publishing' — the scheduler only re-picks 'scheduled' posts.
     */
    public function failed(?\Throwable $exception): void
    {
        $post = SocialPost::find($this->postId);

        if ($post && in_array($post->status, ['publishing', 'scheduled'], true)) {
            $post->update(['status' => 'failed']);
        }

        Log::error('Social post publish job failed permanently', [
            'post_id' => $this->postId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
