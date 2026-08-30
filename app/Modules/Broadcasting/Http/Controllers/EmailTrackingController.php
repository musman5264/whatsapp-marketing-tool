<?php

namespace App\Modules\Broadcasting\Http\Controllers;

use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Shared\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class EmailTrackingController extends Controller
{
    // 1×1 transparent GIF (binary literal)
    private const PIXEL = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00\x21\xf9\x04\x00\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    /**
     * Known mail-scanner / security-prefetch user-agent substrings.
     * These bots pre-fetch images/links BEFORE the recipient sees the email —
     * counting them as opens/clicks would inflate metrics.
     *
     * NOTE: "GoogleImageProxy" is intentionally absent — that proxy fires only
     * when a real Gmail user opens the email, so it IS a genuine open event.
     */
    private const BOT_UA_PATTERNS = [
        'googlebot', 'bingbot', 'yandexbot', 'duckduckbot', 'slurp',
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp',
        'telegrambot', 'semrushbot', 'ahrefsbot', 'mj12bot',
        'barracuda', 'proofpoint', 'mimecast', 'cloudmark',
        'symantec', 'sophos', 'eset mail security',
        // Generic patterns
        'url-scanner', 'safebrowsing', 'preview', 'link-checker', 'linkcheck',
    ];

    // ── Open tracking ─────────────────────────────────────────────────────────

    public function open(Request $request, string $token): \Illuminate\Http\Response
    {
        if (! $this->isLikelyBot($request->userAgent() ?? '')) {
            $recipient = CampaignRecipient::where('tracking_token', $token)->first();

            if ($recipient && ! in_array($recipient->status, ['read', 'failed'], true)) {
                $now     = now();
                $updates = ['status' => 'read', 'read_at' => $now];

                if (! $recipient->delivered_at) {
                    $updates['delivered_at'] = $now;
                }

                $recipient->update($updates);
                $recipient->campaign?->updateTotals();

                Log::channel('json')->info('email.open', [
                    'campaign_id'  => $recipient->campaign_id,
                    'recipient_id' => $recipient->id,
                ]);
            }
        }

        return response(self::PIXEL, 200, [
            'Content-Type'  => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }

    // ── Click tracking ────────────────────────────────────────────────────────

    public function click(Request $request, string $token): \Illuminate\Http\RedirectResponse
    {
        // Reject tampered or expired signatures — prevents open-redirect abuse.
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired tracking link.');
        }

        $url = $request->query('url', '/');

        // Defence-in-depth: only follow http/https even after signature check.
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = '/';
        }

        if (! $this->isLikelyBot($request->userAgent() ?? '')) {
            $recipient = CampaignRecipient::where('tracking_token', $token)->first();

            if ($recipient) {
                $now     = now();
                $updates = ['clicked_at' => $recipient->clicked_at ?? $now];

                // A click proves the email was opened and delivered.
                if (! in_array($recipient->status, ['read', 'failed'], true)) {
                    $updates['status']   = 'read';
                    $updates['read_at']  = $recipient->read_at ?? $now;
                }
                if (! $recipient->delivered_at) {
                    $updates['delivered_at'] = $now;
                }

                $recipient->update($updates);
                $recipient->campaign?->updateTotals();

                Log::channel('json')->info('email.click', [
                    'campaign_id'  => $recipient->campaign_id,
                    'recipient_id' => $recipient->id,
                    'url'          => $url,
                ]);
            }
        }

        return redirect()->away($url);
    }

    // ── Unsubscribe ───────────────────────────────────────────────────────────

    /**
     * GET  /track/email/{token}/unsubscribe  — confirmation page shown to recipient.
     * POST /track/email/{token}/unsubscribe  — RFC 8058 one-click (mail client / List-Unsubscribe header).
     */
    public function unsubscribe(Request $request, string $token): \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
    {
        $recipient = CampaignRecipient::with('contact')->where('unsubscribe_token', $token)->first();

        if (! $recipient) {
            return response($this->unsubscribePage('Invalid or already processed unsubscribe link.', false), 200, ['Content-Type' => 'text/html']);
        }

        $this->processUnsubscribe($recipient);

        if ($request->isMethod('post')) {
            // RFC 8058 one-click: mail clients POST silently, no redirect needed.
            return response('', 200);
        }

        return response(
            $this->unsubscribePage('You have been unsubscribed successfully. You will no longer receive emails from this campaign.', true),
            200,
            ['Content-Type' => 'text/html'],
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function processUnsubscribe(CampaignRecipient $recipient): void
    {
        $now = now();

        $recipient->update(['opted_out_at' => $recipient->opted_out_at ?? $now]);

        // Mark the contact as opted out of email so future campaigns skip them.
        if ($recipient->contact) {
            $recipient->contact->update(['opt_in_email' => false]);
        }

        Log::channel('json')->info('email.unsubscribe', [
            'campaign_id'  => $recipient->campaign_id,
            'recipient_id' => $recipient->id,
            'contact_id'   => $recipient->contact_id,
        ]);
    }

    private function isLikelyBot(string $ua): bool
    {
        if ($ua === '') {
            return true; // No UA at all is suspicious in a browser context.
        }

        $ua = strtolower($ua);

        foreach (self::BOT_UA_PATTERNS as $pattern) {
            if (str_contains($ua, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function unsubscribePage(string $message, bool $success): string
    {
        $color = $success ? '#16a34a' : '#dc2626';
        $icon  = $success ? '✓' : '✗';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f9fafb; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 40px 48px; max-width: 420px; text-align: center; }
        .icon { font-size: 40px; color: {$color}; }
        h1 { font-size: 20px; color: #111827; margin: 16px 0 8px; }
        p { font-size: 14px; color: #6b7280; line-height: 1.6; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">{$icon}</div>
        <h1>Email Preferences</h1>
        <p>{$message}</p>
    </div>
</body>
</html>
HTML;
    }
}
