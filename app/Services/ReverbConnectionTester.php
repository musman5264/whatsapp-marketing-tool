<?php

namespace App\Services;

use Illuminate\Support\Str;
use Pusher\Pusher;
use Throwable;

/**
 * Checks that a Reverb server answers at the given endpoint with the given app credentials,
 * by sending one test event through its HTTP API (the same API the app broadcasts with).
 * It does not check that browsers can open the WebSocket.
 */
class ReverbConnectionTester
{
    /**
     * @param  array{app_id: string, key: string, secret: string, host: string, port: int, scheme: string}  $creds
     * @return string|null  null when the server accepted the event, otherwise a short reason
     */
    public function check(array $creds): ?string
    {
        try {
            $pusher = new Pusher($creds['key'], $creds['secret'], $creds['app_id'], [
                'host' => $creds['host'],
                'port' => $creds['port'],
                'scheme' => $creds['scheme'],
                'useTLS' => $creds['scheme'] === 'https',
                'timeout' => 5,
            ]);
            $pusher->trigger('test-channel', 'test-event', ['message' => 'Connection test']);

            return null;
        } catch (Throwable $e) {
            // The client error repeats the signed request URL; keep only the reason.
            $reason = Str::before($e->getMessage(), ' for http');

            return 'Could not reach Reverb at '.$creds['host'].':'.$creds['port'].' ('.$reason.')';
        }
    }
}
