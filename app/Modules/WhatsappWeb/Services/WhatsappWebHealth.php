<?php

namespace App\Modules\WhatsappWeb\Services;

use App\Http\Controllers\Admin\CronSetupController;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\Waha\WahaAdapter;
use App\Modules\WhatsappWeb\Services\Waha\WahaClient;
use App\Services\RealtimeConfig;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only diagnostic for the WhatsApp Web chain: engine -> webhook -> app ->
 * queue/scheduler -> realtime broadcast. Each check reports ok / warn / fail
 * with a plain-language detail and a hint for the fix. Nothing is changed
 * except one throwaway test broadcast on a diagnostics channel.
 *
 * @phpstan-type Check array{key:string,label:string,status:string,detail:string,hint:string,expected?:string}
 */
class WhatsappWebHealth
{
    /** The supervisor config in docker/supervisor/whatsmine.conf runs `queue:work redis`. */
    private const WORKER_CONNECTION = 'redis';

    public function __construct(private readonly WahaClient $client) {}

    public static function fromSystem(): ?self
    {
        $creds = app(EngineManager::class)->credentials();
        if (! $creds || ! $creds->baseUrl()) {
            return null;
        }

        return new self(new WahaClient($creds->baseUrl(), $creds->apiKey()));
    }

    public static function lastWebhookKey(string $sessionName): string
    {
        return 'whatsapp_web:last_webhook:'.$sessionName;
    }

    /** Called by the webhook controller for every authenticated delivery. */
    public static function recordWebhook(string $sessionName, string $event): void
    {
        try {
            Cache::put(self::lastWebhookKey($sessionName), [
                'at' => now()->toIso8601String(),
                'event' => $event,
            ], now()->addDays(7));
        } catch (Throwable) {
            // diagnostics must never break message handling
        }
    }

    /** @return list<array<string,mixed>> */
    public function run(WhatsappWebSession $session): array
    {
        [$engineCheck, $webhookCheck] = $this->engineAndWebhook($session);

        return [
            $engineCheck,
            $webhookCheck,
            $this->lastWebhook($session),
            $this->lastMessages($session),
            $this->realtime(),
            $this->queue(),
            $this->scheduler(),
        ];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function engineAndWebhook(WhatsappWebSession $session): array
    {
        $engineLabel = 'WhatsApp engine (WAHA)';
        $webhookLabel = 'Webhook registered in WAHA';

        try {
            $resp = $this->client->get("/api/sessions/{$session->session_name}");
        } catch (Throwable $e) {
            $msg = 'Cannot reach WAHA: '.$e->getMessage();

            return [
                $this->check('engine', $engineLabel, 'fail', $msg, 'Check the WAHA base URL and API key in Admin → Integrations → WhatsApp Web, and that the WAHA container is running.'),
                $this->check('webhook', $webhookLabel, 'fail', 'Could not read the webhook because WAHA is unreachable.', 'Fix the engine connection first.'),
            ];
        }

        if (! $resp->successful()) {
            $detail = 'WAHA answered HTTP '.$resp->status().' for session '.$session->session_name.'.';

            return [
                $this->check('engine', $engineLabel, 'fail', $detail, $resp->status() === 404
                    ? 'The session does not exist in WAHA. Reconnect the number (Inbox → Setup → WhatsApp → QR code).'
                    : 'Check the WAHA API key and logs.'),
                $this->check('webhook', $webhookLabel, 'fail', 'Could not read the webhook configuration.', 'Fix the engine connection first.'),
            ];
        }

        $status = strtoupper((string) $resp->json('status'));
        $engine = $this->check('engine', $engineLabel, $status === 'WORKING' ? 'ok' : 'fail',
            'WAHA session status: '.($status ?: 'unknown').'.',
            $status === 'WORKING' ? '' : 'The number is not connected. Reconnect it (Inbox → Setup → WhatsApp → QR code).');

        $expected = route('webhooks.whatsapp-web.receive', ['token' => $session->webhook_token]);
        $hooks = (array) ($resp->json('config.webhooks') ?? []);
        $registered = collect($hooks)->first(fn ($h) => is_array($h) && ($h['url'] ?? null) === $expected);

        if ($registered === null) {
            $urls = collect($hooks)->pluck('url')->filter()->implode(', ') ?: 'none';
            $webhook = $this->check('webhook', $webhookLabel, 'fail',
                'WAHA is sending events to: '.$urls.' — but this app expects: '.$this->maskToken($expected, $session).'.',
                'Press "Sync now" in Inbox → Setup (it re-registers the webhook), or run php artisan whatsapp-web:resubscribe. If the host in the expected URL is wrong, set APP_URL to https://wa.esystematics.com first.');
            $webhook['expected'] = $this->maskToken($expected, $session);

            return [$engine, $webhook];
        }

        $missing = array_values(array_diff(WahaAdapter::WEBHOOK_EVENTS, (array) ($registered['events'] ?? [])));
        $webhook = $missing === []
            ? $this->check('webhook', $webhookLabel, 'ok', 'WAHA sends all expected events to this app.', '')
            : $this->check('webhook', $webhookLabel, 'warn',
                'Webhook URL is right but these events are not subscribed: '.implode(', ', $missing).'.',
                'Press "Sync now" in Inbox → Setup or run php artisan whatsapp-web:resubscribe.');

        return [$engine, $webhook];
    }

    /** @return array<string,mixed> */
    private function lastWebhook(WhatsappWebSession $session): array
    {
        $label = 'Last message from WAHA to this app';
        $last = Cache::get(self::lastWebhookKey($session->session_name));

        if (! is_array($last) || empty($last['at'])) {
            return $this->check('last_webhook', $label, 'fail',
                'No webhook has been received since this version was deployed (or the cache was cleared).',
                'Send a WhatsApp message to the linked number and run the check again. If still nothing, WAHA cannot reach this app: check the webhook URL above and that WAHA can open https://wa.esystematics.com from its container.');
        }

        $at = Carbon::parse($last['at']);

        return $this->check('last_webhook', $label, 'ok',
            'Received "'.($last['event'] ?? '?').'" '.$at->diffForHumans().'.', '');
    }

    /** @return array<string,mixed> */
    private function lastMessages(WhatsappWebSession $session): array
    {
        $label = 'Latest messages stored';

        try {
            $accountId = ChannelAccount::where('workspace_id', $session->workspace_id)
                ->where('channel', 'whatsapp')
                ->where('phone_number_id', $session->session_name)
                ->value('id');

            $base = Message::query()->whereHas('conversation', fn ($q) => $q->where('channel_account_id', $accountId));
            $in = (clone $base)->where('direction', 'in')->max('created_at');
            $out = (clone $base)->where('direction', 'out')->max('created_at');
        } catch (Throwable $e) {
            return $this->check('messages', $label, 'warn', 'Could not read messages: '.$e->getMessage(), '');
        }

        $fmt = fn ($v) => $v ? Carbon::parse($v)->diffForHumans() : 'never';

        return $this->check('messages', $label, 'ok', 'Last incoming: '.$fmt($in).'. Last outgoing: '.$fmt($out).'.', '');
    }

    /** @return array<string,mixed> */
    private function realtime(): array
    {
        $label = 'Live updates (Pusher/Reverb)';
        $cfg = RealtimeConfig::resolve();

        if (! $cfg->enabled) {
            return $this->check('realtime', $label, 'fail', 'The browser is not configured to connect (no Pusher key).',
                'Set the Pusher key in Admin → Pusher settings or PUSHER_APP_KEY in .env.');
        }

        if (! $cfg->canBroadcast) {
            return $this->check('realtime', $label, 'fail',
                'The browser connects (driver '.$cfg->driver.') but the server cannot send events: broadcaster is "'.$cfg->broadcaster.'" or the secret/app id is missing.',
                'Set BROADCAST_CONNECTION=pusher and PUSHER_APP_ID, PUSHER_APP_KEY, PUSHER_APP_SECRET, PUSHER_APP_CLUSTER in the server environment, then run php artisan config:clear.');
        }

        try {
            app(BroadcastFactory::class)->connection()->broadcast(['whatsapp-health-check'], 'health.ping', ['at' => now()->toIso8601String()]);
        } catch (Throwable $e) {
            return $this->check('realtime', $label, 'fail', 'The server could not send a test event to '.$cfg->broadcaster.': '.$e->getMessage(),
                'Check the Pusher app id / key / secret / cluster values match the Pusher dashboard.');
        }

        return $this->check('realtime', $label, 'ok', 'Test event sent through '.$cfg->broadcaster.' (cluster '.$cfg->cluster.').', '');
    }

    /** @return array<string,mixed> */
    private function queue(): array
    {
        $label = 'Background jobs (sync, retries)';
        $default = (string) config('queue.default');
        $detail = 'Jobs are sent to the "'.$default.'" queue.';

        if ($default !== self::WORKER_CONNECTION) {
            if ($default === 'database' && Schema::hasTable('jobs')) {
                $pending = DB::table('jobs')->where('queue', 'whatsapp')->count();
                $detail .= ' '.$pending.' job(s) waiting on the whatsapp queue.';
            }

            return $this->check('queue', $label, 'warn',
                $detail.' The container workers (docker/supervisor/whatsmine.conf) read from "'.self::WORKER_CONNECTION.'", so these jobs may never run (Sync now, webhook retries).',
                'Set QUEUE_CONNECTION=redis (with a Redis service) so the workers pick jobs up, or add workers for "'.$default.'". Then php artisan config:clear.');
        }

        return $this->check('queue', $label, 'ok', $detail.' Whether the workers are running cannot be seen from the app — check the container logs for "app-queue-whatsapp".', '');
    }

    /** @return array<string,mixed> */
    private function scheduler(): array
    {
        $label = 'Scheduler (10-minute chat sync)';
        $raw = Cache::get(CronSetupController::HEARTBEAT_KEY);

        try {
            $at = $raw ? Carbon::parse($raw) : null;
        } catch (Throwable) {
            $at = null;
        }

        if ($at === null || $at->lt(now()->subMinutes(3))) {
            return $this->check('scheduler', $label, 'fail',
                $at ? 'Last scheduler run was '.$at->diffForHumans().'.' : 'The scheduler has never reported a run.',
                'The cron job (php artisan schedule:run every minute) is not running inside the container. Check /etc/cron.d/laravel-scheduler and the cron program in supervisor.');
        }

        return $this->check('scheduler', $label, 'ok', 'Last run '.$at->diffForHumans().'.', '');
    }

    private function maskToken(string $url, WhatsappWebSession $session): string
    {
        return $session->webhook_token ? str_replace($session->webhook_token, substr($session->webhook_token, 0, 6).'…', $url) : $url;
    }

    /** @return array<string,mixed> */
    private function check(string $key, string $label, string $status, string $detail, string $hint): array
    {
        return compact('key', 'label', 'status', 'detail', 'hint');
    }
}
