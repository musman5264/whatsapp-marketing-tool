<?php

namespace App\Providers;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Registers all broadcast channel authorisation callbacks directly.
 *
 * In Laravel 11/12 channels are normally loaded from routes/channels.php via
 * Application::configure(channels: ...). On some deployments that file isn't
 * picked up (route cache / opcache / missing file) which makes every
 * /broadcasting/auth request fall through to AccessDeniedHttpException at
 * Broadcaster::verifyUserCanAccessChannel because no pattern matches.
 *
 * Registering them in a real service provider guarantees they are always
 * available, regardless of caching state.
 */
class BroadcastChannelsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Defer channel registration until after all service providers have booted.
        //
        // PusherSettingsServiceProvider also uses app->booted() to switch the
        // default broadcaster from 'reverb' (env default) to 'pusher' (DB creds).
        // Because PusherSettingsServiceProvider is listed first in bootstrap/providers.php,
        // its booted() callback fires before ours, so by the time we call
        // Broadcast::channel() the correct broadcaster is already active and channels
        // land on the right driver instance.  Without this deferral, channels would be
        // registered on 'reverb' and the 'pusher' broadcaster used at auth time would
        // have none — causing AccessDeniedHttpException (403) for every private channel.
        $this->app->booted(function () {

            // Default user-specific private channel (used by Laravel notifications)
            Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
                $allowed = (int) $user->id === $id;
                if (! $allowed) {
                    Log::warning('broadcast.auth.denied user channel', [
                        'authenticated_id' => $user->id,
                        'requested_id' => $id,
                    ]);
                }

                return $allowed;
            });

            // Workspace-wide channel: all members of a workspace
            Broadcast::channel('workspace.{workspaceId}', function (User $user, int $workspaceId) {
                Log::info('broadcast.auth.check workspace channel', [
                    'user_id' => $user->id,
                    'workspace_id' => $workspaceId,
                ]);

                return self::userCanAccessWorkspace($user, $workspaceId);
            });

            // Per-conversation private channel
            Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
                Log::info('broadcast.auth.check conversation channel', [
                    'user_id' => $user->id,
                    'conversation_id' => $conversationId,
                ]);

                $conversation = Conversation::find($conversationId);
                if (! $conversation) {
                    return false;
                }

                return self::userCanAccessWorkspace($user, (int) $conversation->workspace_id);
            });

            // Presence channel: tracks who is currently viewing a conversation
            Broadcast::channel('presence-conversation.{conversationId}', function (User $user, int $conversationId) {
                $conversation = Conversation::find($conversationId);
                if (! $conversation) {
                    return false;
                }
                if (! self::userCanAccessWorkspace($user, (int) $conversation->workspace_id)) {
                    return false;
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => null,
                ];
            });
        });
    }

    /**
     * Whether the user can access the given workspace. Mirrors InboxController::authorise
     * (current/primary workspace), plus pivot membership, ownership and same-client.
     */
    public static function userCanAccessWorkspace(User $user, int $workspaceId): bool
    {
        if ((int) $user->workspace_id === $workspaceId) {
            return true;
        }

        $current = $user->current_workspace_id ?? null;
        if ($current && (int) $current === $workspaceId) {
            return true;
        }

        if ($user->accessibleWorkspaces()->contains('id', $workspaceId)) {
            return true;
        }

        if ($user->client_id) {
            $belongsToClient = Workspace::where('id', $workspaceId)
                ->where('client_id', $user->client_id)
                ->exists();
            if ($belongsToClient) {
                return true;
            }
        }

        Log::warning('broadcast.auth.denied workspace access check', [
            'user_id' => $user->id,
            'user_workspace_id' => $user->workspace_id,
            'user_current_workspace_id' => $current,
            'user_client_id' => $user->client_id,
            'requested_workspace_id' => $workspaceId,
        ]);

        return false;
    }
}
