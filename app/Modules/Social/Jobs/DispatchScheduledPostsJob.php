<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialPost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DispatchScheduledPostsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        // Atomically flip status to 'publishing' before dispatching so a second
        // scheduler tick cannot pick up the same post and dispatch it twice.
        $affected = SocialPost::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get(['id']);

        foreach ($affected as $post) {
            // updateOrFail pattern: only dispatch if we are the one who flipped the status.
            $updated = SocialPost::where('id', $post->id)
                ->where('status', 'scheduled')
                ->update(['status' => 'publishing']);

            if ($updated) {
                PublishSocialPostJob::dispatch($post->id)->onQueue('social');
            }
        }

        $this->requeueStuckPosts();
    }

    /**
     * Safety net: a post stays in 'publishing' forever if the worker died hard
     * (SIGKILL, reboot) before the publish job's failed() hook could run. The
     * per-account link statuses make re-publishing idempotent, so re-dispatch.
     */
    private function requeueStuckPosts(): void
    {
        SocialPost::where('status', 'publishing')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->get(['id', 'updated_at'])
            ->each(function (SocialPost $post) {
                Log::warning('Requeueing social post stuck in publishing', ['post_id' => $post->id]);

                // Reset the staleness clock so the next ticks don't re-dispatch
                // the same post every minute while it waits in the queue.
                $post->touch();

                PublishSocialPostJob::dispatch($post->id)->onQueue('social');
            });
    }
}
