<?php

namespace App\Modules\WhatsappWeb\Console;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\EngineManager;
use Illuminate\Console\Command;

/**
 * Asks WAHA for the real number of every contact of a session (GET /api/{session}/lids/{lid}, then
 * /api/contacts) and lists the ones stored under a LID that WAHA can now map. With --apply each is
 * recorded and its messages moved, by running whatsapp-web:lid-map --apply.
 *
 *   php artisan whatsapp-web:lid-resolve --session=ws-2           dry run
 *   php artisan whatsapp-web:lid-resolve --session=ws-2 --apply   writes
 */
class LidResolveCommand extends Command
{
    protected $signature = 'whatsapp-web:lid-resolve
        {--session= : The WhatsApp Web session name, e.g. ws-2}
        {--apply : Record every mapping found and move the messages. Without it, nothing changes.}';

    protected $description = 'Ask WAHA for the real number of contacts stored under a LID (dry run unless --apply)';

    public function handle(EngineManager $engines): int
    {
        $session = WhatsappWebSession::where('session_name', (string) $this->option('session'))->first();
        if ($session === null) {
            $this->error('Give an existing WhatsApp Web session with --session=<name>.');

            return self::FAILURE;
        }

        $accountIds = ChannelAccount::where('workspace_id', $session->workspace_id)
            ->where('phone_number_id', $session->session_name)->pluck('id');
        $contacts = Contact::whereIn('id', Conversation::whereIn('channel_account_id', $accountIds)->pluck('contact_id'))->get();

        $adapter = $engines->adapter();
        $found = $none = $failed = 0;

        foreach ($contacts as $contact) {
            $digits = preg_replace('/\D+/', '', (string) $contact->phone_e164);
            if ($digits === '') {
                continue;
            }

            try {
                $resolved = $adapter->resolveContact($session->session_name, $digits.'@lid');
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("+{$digits}: WAHA lookup failed: ".$e->getMessage());

                continue;
            }

            $phone = $resolved['phone_e164'] ?? null;
            if ($phone === null || $phone === '+'.$digits) {
                $none++;

                continue;
            }

            $found++;
            $this->line("+{$digits} -> {$phone}".(($resolved['name'] ?? null) ? " ({$resolved['name']})" : ''));
            if ($this->option('apply')) {
                $this->call('whatsapp-web:lid-map', [
                    'lid' => $digits, 'phone' => $phone, '--session' => $session->session_name, '--apply' => true,
                ]);
            }
        }

        $this->info("Checked {$contacts->count()} contact(s): {$found} map to a real number, {$none} have none (real number already, or WAHA does not know it), {$failed} lookup(s) failed.");
        if (! $this->option('apply') && $found > 0) {
            $this->info('Nothing was changed. Run with --apply to record them and move the messages.');
        }

        return self::SUCCESS;
    }
}
