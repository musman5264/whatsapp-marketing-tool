<?php

namespace App\Modules\WhatsappWeb\Console;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Models\WhatsappWebLidMapping;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Records the phone number of a LID sender and moves the messages that were stored under the LID
 * digits (a contact named +<lid digits>) into the real contact's conversation.
 *
 *   php artisan whatsapp-web:lid-map 120297755815945 +923345266444 --session=ws-1           dry run
 *   php artisan whatsapp-web:lid-map 120297755815945 +923345266444 --session=ws-1 --apply   writes
 *
 * The stray contact is kept, not deleted. See app/logic/waha-sync-and-labels.md.
 */
class LidMappingCommand extends Command
{
    protected $signature = 'whatsapp-web:lid-map
        {lid : The LID digits, with or without @lid}
        {phone : The real phone number, e.g. +923345266444}
        {--session= : The WhatsApp Web session name, e.g. ws-1}
        {--apply : Record the mapping and move the messages. Without it, nothing changes.}';

    protected $description = 'Map a LID sender to its phone number and move its stored messages to that contact (dry run unless --apply)';

    public function handle(): int
    {
        $lid = preg_replace('/\D+/', '', explode('@', (string) $this->argument('lid'))[0]);
        $phone = '+'.preg_replace('/\D+/', '', (string) $this->argument('phone'));
        $sessionName = (string) $this->option('session');

        $session = $sessionName !== '' ? WhatsappWebSession::where('session_name', $sessionName)->first() : null;
        if ($session === null) {
            $this->error('Give an existing WhatsApp Web session with --session=<name>.');

            return self::FAILURE;
        }
        if ($lid === '' || strlen($phone) < 8) {
            $this->error('Give the LID digits and a full phone number, e.g. +923345266444.');

            return self::FAILURE;
        }

        $workspaceId = $session->workspace_id;
        $stray = Contact::where('workspace_id', $workspaceId)->where('phone_e164', '+'.$lid)->first();
        $real = Contact::where('workspace_id', $workspaceId)->where('phone_e164', $phone)->first();

        $plan = $this->plan($stray, $real);
        $this->showPlan($plan, $real);

        if (! $this->option('apply')) {
            $this->info('Nothing was changed. Run with --apply to record the mapping and move the messages.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($session, $lid, $phone, $plan, $real) {
            WhatsappWebLidMapping::updateOrCreate(
                ['session_name' => $session->session_name, 'lid' => $lid],
                ['phone_e164' => $phone, 'name' => $real?->getAttribute('name')],
            );

            foreach ($plan as $step) {
                if ($step['action'] === 'merge') {
                    Message::where('conversation_id', $step['conversation']->id)
                        ->update(['conversation_id' => $step['target']->id]);
                } elseif ($step['action'] === 'move' && $real !== null) {
                    $step['conversation']->update(['contact_id' => $real->id]);
                }
            }
        });

        $this->info('Mapping recorded.');

        return self::SUCCESS;
    }

    /**
     * What --apply would do, one step per conversation of the stray contact.
     *
     * @return list<array{action: string, conversation: Conversation, target: ?Conversation, messages: int}>
     */
    private function plan(?Contact $stray, ?Contact $real): array
    {
        if ($stray === null || $real === null || $stray->id === $real->id) {
            return [];
        }

        $steps = [];
        foreach (Conversation::where('contact_id', $stray->id)->get() as $conversation) {
            $target = Conversation::where('contact_id', $real->id)
                ->where('channel_account_id', $conversation->getAttribute('channel_account_id'))
                ->first();

            $steps[] = [
                'action' => $target !== null ? 'merge' : 'move',
                'conversation' => $conversation,
                'target' => $target,
                'messages' => Message::where('conversation_id', $conversation->id)->count(),
            ];
        }

        return $steps;
    }

    /** @param list<array{action: string, conversation: Conversation, target: ?Conversation, messages: int}> $plan */
    private function showPlan(array $plan, ?Contact $real): void
    {
        if ($real === null) {
            $this->warn('No contact with that phone number in this workspace. The mapping can still be recorded with --apply.');
        }

        if ($plan === []) {
            $this->line('No messages are stored under the LID digits. Nothing to move.');

            return;
        }

        foreach ($plan as $step) {
            $this->line(sprintf(
                '%s conversation %d: %d message(s) %s',
                $step['action'] === 'merge' ? 'Merge' : 'Move',
                $step['conversation']->id,
                $step['messages'],
                $step['action'] === 'merge' ? "into conversation {$step['target']->id}" : 'to the real contact',
            ));
        }
    }
}
