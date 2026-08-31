<?php

namespace App\Modules\WhatsappWeb\Console;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\WhatsappWeb\Models\WhatsappWebSession;
use App\Modules\WhatsappWeb\Services\EngineManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Operator diagnostics + reset for the WhatsApp Web (QR) integration.
 *
 *   php artisan whatsapp-web:status            show app + engine state
 *   php artisan whatsapp-web:status --reset    also wipe the local session + shadow ChannelAccount
 *   php artisan whatsapp-web:status --reset --engine   also delete the engine-side session
 */
class WhatsappWebCommand extends Command
{
    protected $signature = 'whatsapp-web:status
        {--reset : delete the local session + ChannelAccount rows}
        {--engine : also delete the session on the WAHA engine}
        {--connect= : run the full connect flow for the given workspace id and report the result}
        {--waha-probe : hit each WAHA endpoint directly and show the raw status}';

    protected $description = 'Diagnose, connect, or reset the WhatsApp Web (QR) integration';

    public function handle(EngineManager $engines): int
    {
        $this->line('APP_URL           : '.config('app.url'));

        $creds = CredentialResolver::system()->whatsappWeb();
        $this->line('engine configured : '.($engines->enabled() ? 'yes' : 'NO'));
        if ($creds) {
            $this->line('engine base_url   : '.$creds->baseUrl());
            $this->line('engine           : '.$creds->engine());
            $this->line('api_key           : '.($creds->apiKey() ? substr($creds->apiKey(), 0, 3).'…'.substr($creds->apiKey(), -3).' (len '.strlen($creds->apiKey()).')' : 'NOT set'));
            $this->line('webhook secret   : '.($creds->webhookSecret() ? 'set' : 'NOT set'));
        }

        if ($this->option('waha-probe') && $creds && $creds->baseUrl()) {
            $this->newLine();
            $this->line('--- raw WAHA endpoint probe (from this server) ---');
            $h = $creds->apiKey() ? ['X-Api-Key' => $creds->apiKey()] : [];
            foreach (['/api/version', '/api/server/status', '/api/sessions', '/api/sessions/ws-1', '/api/sessions/default'] as $p) {
                try {
                    $r = Http::withHeaders($h)->timeout(15)->get($creds->baseUrl().$p);
                    $this->line(str_pad($p, 28).' -> '.$r->status().'  '.mb_substr($r->body(), 0, 90));
                } catch (\Throwable $e) {
                    $this->line(str_pad($p, 28).' -> EXC  '.$e->getMessage());
                }
            }
        }

        $session = WhatsappWebSession::first();
        if ($session) {
            $this->line('local session    : '.$session->session_name.'  status='.$session->status
                .'  phone='.($session->phone_e164 ?: '-'));
            $this->line('webhook url      : '.route('webhooks.whatsapp-web.receive', ['token' => $session->webhook_token]));
        } else {
            $this->line('local session    : NONE');
        }

        $ca = ChannelAccount::where('provider', 'whatsapp_web')->first();
        $this->line('channel account  : '.($ca ? $ca->phone_number_id.'  status='.$ca->status : 'NONE'));

        // Engine-side view
        if ($creds && $creds->baseUrl()) {
            try {
                $h = $creds->apiKey() ? ['X-Api-Key' => $creds->apiKey()] : [];
                $resp = Http::withHeaders($h)->timeout(15)->get($creds->baseUrl().'/api/sessions');
                if ($resp->successful()) {
                    $this->newLine();
                    $this->line('--- engine sessions ---');
                    foreach ($resp->json() ?: [] as $s) {
                        $this->line('  '.($s['name'] ?? '?').'  status='.($s['status'] ?? '?')
                            .'  number='.($s['me']['id'] ?? 'none'));
                        $this->line('    webhook: '.($s['config']['webhooks'][0]['url'] ?? 'none'));
                    }
                } else {
                    $this->warn('engine /api/sessions -> HTTP '.$resp->status());
                }
            } catch (\Throwable $e) {
                $this->warn('engine unreachable: '.$e->getMessage());
            }
        }

        if ($ws = $this->option('connect')) {
            $this->newLine();
            $this->line('--- connect flow (workspace '.$ws.') ---');
            try {
                $prov = app(\App\Modules\WhatsappWeb\Services\SessionProvisioner::class);
                $row = $prov->ensure((int) $ws, 'waha');
                $this->info('session row ensured: '.$row->session_name.'  status='.$row->status);

                $webhookUrl = route('webhooks.whatsapp-web.receive', ['token' => $row->webhook_token]);
                $this->line('webhook url: '.$webhookUrl);

                $engines->adapter()->startSession($row->session_name, $webhookUrl);
                $this->info('startSession() OK');

                // Raw check — what does GET /api/sessions/{name} actually return from here?
                $creds2 = CredentialResolver::system()->whatsappWeb();
                $hh = $creds2->apiKey() ? ['X-Api-Key' => $creds2->apiKey()] : [];
                $raw = Http::withHeaders($hh)->timeout(20)->get($creds2->baseUrl().'/api/sessions/'.$row->session_name);
                $this->line('raw GET /api/sessions/'.$row->session_name.' -> HTTP '.$raw->status());
                $this->line('  body: '.mb_substr($raw->body(), 0, 300));

                $st = $engines->adapter()->getStatus($row->session_name);
                $this->info('adapter getStatus(): '.$st);
            } catch (\Throwable $e) {
                $this->error(get_class($e).': '.$e->getMessage());
                $this->line('  at '.$e->getFile().':'.$e->getLine());
            }

            return self::SUCCESS;
        }

        if ($this->option('reset')) {
            $this->newLine();

            if ($this->option('engine') && $session && $creds && $creds->baseUrl()) {
                try {
                    $h = $creds->apiKey() ? ['X-Api-Key' => $creds->apiKey()] : [];
                    $base = $creds->baseUrl();
                    Http::withHeaders($h)->timeout(30)->post("{$base}/api/sessions/{$session->session_name}/logout");
                    Http::withHeaders($h)->timeout(30)->delete("{$base}/api/sessions/{$session->session_name}");
                    $this->info('deleted engine session '.$session->session_name);
                } catch (\Throwable $e) {
                    $this->warn('engine delete failed: '.$e->getMessage());
                }
            }

            ChannelAccount::where('provider', 'whatsapp_web')->delete();
            WhatsappWebSession::query()->delete();
            $this->info('local session + channel account rows deleted. Reconnect from Inbox → Setup → WhatsApp → QR code.');
        }

        return self::SUCCESS;
    }
}
