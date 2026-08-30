<?php

namespace Tests\Feature\Realtime;

use App\Events\MessageReceived;
use App\Listeners\SendNewMessageNotification;
use App\Models\NotificationPreference;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\NewMessageNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_message_notification_sent_to_assigned_user(): void
    {
        Notification::fake();

        $ctx = $this->createWorkspaceContext();
        $user = $ctx['user'];
        $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);
        $conv = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_user_id' => $user->id,
        ]);
        $message = Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'body' => 'Test',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $listener = new SendNewMessageNotification;
        $listener->handle(new MessageReceived($message));

        Notification::assertSentTo($user, NewMessageNotification::class);
    }

    public function test_notification_not_sent_when_mail_preference_disabled(): void
    {
        Notification::fake();

        $ctx = $this->createWorkspaceContext();
        $user = $ctx['user'];
        $contact = Contact::factory()->create(['workspace_id' => $ctx['workspace']->id]);
        $conv = Conversation::create([
            'workspace_id' => $ctx['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_user_id' => $user->id,
        ]);
        $message = Message::create([
            'conversation_id' => $conv->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'body' => 'Test opt out',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        NotificationPreference::create([
            'user_id' => $user->id,
            'event' => 'new_message',
            'channel' => 'mail',
            'enabled' => false,
        ]);

        $listener = new SendNewMessageNotification;
        $listener->handle(new MessageReceived($message));

        Notification::assertSentTo($user, NewMessageNotification::class, function ($notification) use ($user) {
            $via = $notification->via($user);

            return ! in_array('mail', $via);
        });
    }
}
