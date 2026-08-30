<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Events\MessageSent;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use App\Modules\Broadcasting\Services\CampaignPersonalizer;
use App\Modules\Broadcasting\Services\Sms\SmsDriverManager;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\SmtpConfiguration;
use App\Services\Mail\MailService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendCampaignMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $campaignId,
        public readonly int $contactId,
    ) {}

    public function handle(CampaignPersonalizer $personalizer): void
    {
        $campaign = Campaign::find($this->campaignId);
        $contact = Contact::find($this->contactId);

        if (! $campaign || ! $contact) {
            return;
        }

        // Soft-stop on paused / cancelled / failed campaigns.
        if (in_array($campaign->status, ['paused', 'failed', 'completed'], true)) {
            return;
        }

        $recipient = CampaignRecipient::where('campaign_id', $this->campaignId)
            ->where('contact_id', $this->contactId)
            ->first();

        // Pre-flight opt-in check (defence in depth — LaunchCampaignJob already filters these).
        if ($this->isOptedOut($campaign, $contact)) {
            $recipient?->update([
                'status' => 'failed',
                'failed_reason' => 'opted_out',
                'opted_out_at' => now(),
            ]);

            return;
        }

        try {
            $trackingToken = $campaign->channel === 'email' ? Str::random(32) : null;
            // Unsubscribe token is always generated for email — CAN-SPAM requires opt-out in every commercial email.
            $unsubscribeToken = $campaign->channel === 'email' ? Str::random(32) : null;

            $sent = match ($campaign->channel) {
                'whatsapp' => $this->sendWhatsApp($campaign, $contact, $personalizer),
                'sms' => $this->sendSms($campaign, $contact, $personalizer),
                'email' => $this->sendEmail($campaign, $contact, $personalizer, $trackingToken, $unsubscribeToken),
            };

            $updateData = [
                'status' => 'sent',
                'provider_message_id' => $sent['id'],
                'sent_at' => now(),
                'failed_reason' => null,
            ];

            if ($trackingToken !== null) {
                $updateData['tracking_token'] = $trackingToken;
            }

            if ($unsubscribeToken !== null) {
                $updateData['unsubscribe_token'] = $unsubscribeToken;
            }

            $recipient?->update($updateData);

            // Mirror the outbound send into the Inbox so conversations & reply threads
            // stay coherent and the webhook status pipeline (which keys off
            // provider_message_id) can update the inbox row too.
            $this->syncToInbox($campaign, $contact, $sent);

            UsageMeter::track($campaign->workspace_id, 'messages_'.$campaign->channel);
            if ($campaign->channel === 'whatsapp') {
                UsageMeter::track($campaign->workspace_id, 'whatsapp_messages');
            }

            Log::channel('json')->info('campaign.message.sent', [
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'channel' => $campaign->channel,
                'message_id' => $sent['id'],
            ]);
        } catch (\Throwable $e) {
            $recipient?->update([
                'status' => 'failed',
                'failed_reason' => substr($e->getMessage(), 0, 512),
            ]);
            Log::channel('json')->warning('campaign.message.failed', [
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'channel' => $campaign->channel,
                'error' => $e->getMessage(),
            ]);
        }

        // Lazily update campaign totals every 100th message; FinalizeCampaignJob ensures
        // a final, authoritative refresh once all sends complete.
        if (rand(1, 100) === 1) {
            $campaign->updateTotals();
        }
    }

    private function isOptedOut(Campaign $campaign, Contact $contact): bool
    {
        return match ($campaign->channel) {
            'whatsapp' => ! $contact->opt_in_whatsapp || ! $contact->phone_e164,
            'sms' => ! $contact->opt_in_sms || ! $contact->phone_e164,
            'email' => ! $contact->opt_in_email || ! $contact->email,
            default => true,
        };
    }

    /**
     * @return array{id: string, body: string, type: string, payload: array<string, mixed>}
     */
    private function sendWhatsApp(Campaign $campaign, Contact $contact, CampaignPersonalizer $personalizer): array
    {
        $client = $campaign->whatsapp_phone_number_id
            ? CloudApiClient::forPhoneNumber($campaign->whatsapp_phone_number_id, $campaign->workspace_id)
            : CloudApiClient::forWorkspace($campaign->workspace_id);
        if (! $client) {
            throw new \RuntimeException('No WhatsApp client for workspace '.$campaign->workspace_id);
        }

        $tpl = $campaign->template_ref ?? [];
        $name = $tpl['name'] ?? '';
        $language = $tpl['language'] ?? 'en';
        $components = $tpl['components'] ?? [];

        if ($name === '') {
            throw new \RuntimeException('Campaign has no WhatsApp template name configured.');
        }

        // Substitute per-recipient variables in template components.
        $rendered = $personalizer->renderTemplateComponents(
            is_array($components) ? $components : [],
            $contact,
        );

        // Header media: Meta frequently fails to fetch a header image/video/doc
        // by URL ("Media upload error", code 131053). Upload the file to WhatsApp
        // once and reference it by media id instead — reliable and independent of
        // the URL being publicly reachable by Meta. Cached so all recipients in
        // the campaign reuse the same upload. We keep the original link-based
        // `$rendered` for the inbox mirror (so the header image still previews
        // there) and send with the media-id version.
        $forSend = $this->resolveHeaderMediaIds($client, $rendered);

        $phone = $contact->phone_e164;
        if (! str_starts_with($phone, '+')) {
            $phone = '+'.$phone;
        }

        $resp = $client->sendTemplate($phone, $name, $language, $forSend);

        if (! $resp->successful()) {
            $metaError = $resp->json('error.message') ?? $resp->body();
            throw new \RuntimeException('WhatsApp send failed: '.$metaError);
        }

        // Build a full template `definition` (canonical components + merged
        // parameters) so the inbox can render the message body — the params-only
        // `components` array alone carries no body text and renders as an empty
        // bubble. Mirrors how the Inbox template picker stores `definition`.
        $definition = $this->buildTemplateDefinition($campaign, $name, $language, $rendered);

        $template = [
            'name' => $name,
            'language' => $language,
            'components' => $rendered,
        ];
        if ($definition !== null) {
            $template['definition'] = $definition;
        }

        return [
            'id' => $resp->json('messages.0.id', ''),
            'body' => $this->summariseTemplateForInbox($name, $rendered, $definition),
            'type' => 'template',
            'payload' => [
                'template' => $template,
            ],
        ];
    }

    /**
     * Merge the per-recipient rendered parameters back into the template's
     * canonical Meta definition (the version synced from Meta, with `{{N}}`
     * placeholders in the BODY/HEADER text). The result is the shape the inbox
     * renderer expects under `payload.template.definition`.
     *
     * @param  array<int, mixed>  $rendered  Meta send-shape components (params only)
     * @return array<int, mixed>|null
     */
    private function buildTemplateDefinition(Campaign $campaign, string $name, string $language, array $rendered): ?array
    {
        $template = WhatsappTemplate::where('workspace_id', $campaign->workspace_id)
            ->where('name', $name)
            ->where('language', $language)
            ->first();

        $canonical = is_array($template?->components) ? $template->components : null;
        if (! $canonical) {
            return null;
        }

        // Index the rendered parameters by component type (header / body) so we
        // can attach them to the matching canonical component.
        $paramsByType = [];
        foreach ($rendered as $comp) {
            if (is_array($comp) && isset($comp['type'])) {
                $paramsByType[strtolower((string) $comp['type'])] = $comp['parameters'] ?? [];
            }
        }

        return array_map(function ($comp) use ($paramsByType) {
            if (! is_array($comp)) {
                return $comp;
            }
            $type = strtolower((string) ($comp['type'] ?? ''));
            if (in_array($type, ['header', 'body'], true) && isset($paramsByType[$type])) {
                $comp['parameters'] = $paramsByType[$type];
            }

            return $comp;
        }, $canonical);
    }

    /**
     * Replace header media `link`s with WhatsApp `media_id`s by uploading the
     * file to the Cloud API. Avoids Meta's "Media upload error" (131053) that
     * happens when it can't download a header image/video/doc from the URL.
     *
     * @param  array<int, mixed>  $components
     * @return array<int, mixed>
     */
    private function resolveHeaderMediaIds(CloudApiClient $client, array $components): array
    {
        return array_map(function ($component) use ($client) {
            if (! is_array($component) || strtolower((string) ($component['type'] ?? '')) !== 'header') {
                return $component;
            }
            if (! isset($component['parameters']) || ! is_array($component['parameters'])) {
                return $component;
            }

            $component['parameters'] = array_map(function ($param) use ($client) {
                if (! is_array($param)) {
                    return $param;
                }
                foreach (['image', 'video', 'document'] as $mediaKey) {
                    if (isset($param[$mediaKey]['link']) && is_string($param[$mediaKey]['link']) && $param[$mediaKey]['link'] !== '') {
                        $mediaId = $this->uploadHeaderMedia($client, (string) $param[$mediaKey]['link']);
                        $filename = $param[$mediaKey]['filename'] ?? null;
                        $param[$mediaKey] = array_filter(
                            ['id' => $mediaId, 'filename' => $filename],
                            fn ($v) => $v !== null && $v !== '',
                        );
                    }
                }

                return $param;
            }, $component['parameters']);

            return $component;
        }, $components);
    }

    /**
     * Download a media URL and upload it to WhatsApp, returning the media id.
     * Cached per phone number + URL so a campaign uploads each asset once.
     */
    private function uploadHeaderMedia(CloudApiClient $client, string $url): string
    {
        $cacheKey = 'wa_header_media:'.$client->phoneNumberId().':'.sha1($url);

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($client, $url) {
            $resp = Http::timeout(30)->get($url);
            if (! $resp->successful()) {
                throw new \RuntimeException("Could not download header media (HTTP {$resp->status()}) from {$url}");
            }

            $mime = trim(explode(';', (string) ($resp->header('Content-Type') ?: $this->guessMimeFromUrl($url)))[0]);

            $tmp = tempnam(sys_get_temp_dir(), 'wamedia_');
            file_put_contents($tmp, $resp->body());

            try {
                $mediaId = $client->uploadMedia($tmp, $mime);
            } finally {
                @unlink($tmp);
            }

            if ($mediaId === '') {
                throw new \RuntimeException('WhatsApp returned no media id for header upload.');
            }

            return $mediaId;
        });
    }

    private function guessMimeFromUrl(string $url): string
    {
        $ext = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    /**
     * @return array{id: string, body: string, type: string, payload: array<string, mixed>}
     */
    private function sendSms(Campaign $campaign, Contact $contact, CampaignPersonalizer $personalizer): array
    {
        $driver = SmsDriverManager::forWorkspace($campaign->workspace_id);
        $body = $campaign->payload_json['body'] ?? '';
        $body = $personalizer->renderText($body, $contact);

        if (trim($body) === '') {
            throw new \RuntimeException('SMS body is empty after personalization.');
        }

        $result = $driver->send($contact->phone_e164, $body);

        if (! $result->success) {
            throw new \RuntimeException($result->error);
        }

        return [
            'id' => $result->messageId,
            'body' => $body,
            'type' => 'text',
            'payload' => ['body' => $body],
        ];
    }

    /**
     * @return array{id: string, body: string, type: string, payload: array<string, mixed>}
     */
    private function sendEmail(
        Campaign $campaign,
        Contact $contact,
        CampaignPersonalizer $personalizer,
        ?string $trackingToken = null,
        ?string $unsubscribeToken = null,
    ): array {
        if (empty($contact->email)) {
            throw new \RuntimeException('Contact has no email address.');
        }

        $payload = $campaign->payload_json ?? [];
        $trackOpens  = (bool) ($payload['track_opens'] ?? true);
        $trackClicks = (bool) ($payload['track_clicks'] ?? false);

        // Build the unsubscribe URL — required by CAN-SPAM / GDPR for every commercial email.
        $unsubscribeUrl = $unsubscribeToken
            ? route('track.email.unsubscribe', ['token' => $unsubscribeToken])
            : null;

        // Render body with {{context.unsubscribe_url}} support so authors can place
        // the link themselves; we also inject a fallback footer below.
        $context = $unsubscribeUrl ? ['unsubscribe_url' => $unsubscribeUrl] : [];
        $subject  = $personalizer->renderText($payload['subject'] ?? 'No subject', $contact);
        $body     = $personalizer->renderText($payload['body'] ?? '', $contact, $context);

        // ── Click tracking: wrap links with signed redirect URLs ──────────────
        if ($trackingToken !== null && $trackClicks) {
            $body = $this->wrapLinksForClickTracking($body, $trackingToken);
        }

        // ── Open tracking: 1×1 transparent pixel ─────────────────────────────
        if ($trackingToken !== null && $trackOpens) {
            $pixelUrl = route('track.email.open', ['token' => $trackingToken]);
            $pixel = '<img src="'.e($pixelUrl).'" width="1" height="1" alt="" style="display:none;border:0;" />';
            $body = str_contains($body, '</body>')
                ? str_replace('</body>', $pixel.'</body>', $body)
                : $body.$pixel;
        }

        // ── Unsubscribe footer: injected unless the author already used the token ──
        if ($unsubscribeUrl && ! str_contains($body, (string) $unsubscribeToken)) {
            $footer = $this->buildUnsubscribeFooter($unsubscribeUrl);
            $body = str_contains($body, '</body>')
                ? str_replace('</body>', $footer.'</body>', $body)
                : $body.$footer;
        }

        // ── RFC 2369 / RFC 8058 List-Unsubscribe headers ──────────────────────
        $extraHeaders = [];
        if ($unsubscribeUrl) {
            $extraHeaders['List-Unsubscribe']      = '<'.$unsubscribeUrl.'>, <mailto:'.($payload['reply_to'] ?? config('mail.from.address')).'?subject=unsubscribe>';
            $extraHeaders['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        $smtp = WorkspaceSmtpConfig::forWorkspace($campaign->workspace_id)
            ?? SmtpConfiguration::getActive();

        $fromEmail = filled($payload['from_email'] ?? '') ? $payload['from_email'] : null;
        $fromName  = filled($payload['from_name']  ?? '') ? $payload['from_name']  : null;
        $replyTo   = filled($payload['reply_to']   ?? '') ? $payload['reply_to']   : null;

        if ($smtp) {
            $mailService = app(MailService::class);
            $mailService->sendRaw($smtp, $contact->email, $subject, $body, $extraHeaders, $fromEmail, $fromName, $replyTo);
        } else {
            Mail::html($body, function ($m) use ($contact, $subject, $extraHeaders, $fromEmail, $fromName, $replyTo) {
                $m->to($contact->email, $contact->full_name)->subject($subject);
                if ($fromEmail) {
                    $m->from($fromEmail, $fromName ?: null);
                }
                if ($replyTo) {
                    $m->replyTo($replyTo);
                }
                foreach ($extraHeaders as $name => $value) {
                    $m->getHeaders()->addTextHeader($name, $value);
                }
            });
        }

        $id = 'email:'.uniqid();

        return [
            'id'      => $id,
            'body'    => $subject,
            'type'    => 'text',
            'payload' => ['subject' => $subject, 'body' => $body],
        ];
    }

    /**
     * Wrap every <a href> in the HTML body with a signed click-tracking redirect.
     * Using signed URLs prevents the ?url= parameter from being tampered to create
     * an open redirect phishing vector.
     */
    private function wrapLinksForClickTracking(string $html, string $token): string
    {
        return preg_replace_callback(
            '/<a(\s[^>]*?)href=["\']([^"\']+)["\']/i',
            function (array $matches) use ($token): string {
                $attrs = $matches[1];
                $url   = $matches[2];

                // Skip mailto, tel, anchor, and already-tracked links.
                if (
                    str_starts_with($url, 'mailto:') ||
                    str_starts_with($url, 'tel:') ||
                    str_starts_with($url, '#') ||
                    str_contains($url, 'track/email/')
                ) {
                    return $matches[0];
                }

                // Signed route: the signature covers token + url, preventing tampering.
                $trackUrl = \Illuminate\Support\Facades\URL::signedRoute(
                    'track.email.click',
                    ['token' => $token, 'url' => $url],
                    absolute: true,
                );

                return '<a'.$attrs.'href="'.e($trackUrl).'"';
            },
            $html,
        ) ?? $html;
    }

    private function buildUnsubscribeFooter(string $unsubscribeUrl): string
    {
        return '
<div style="margin-top:32px;padding-top:16px;border-top:1px solid #e5e7eb;text-align:center;font-family:sans-serif;font-size:12px;color:#6b7280;">
    You received this email because you opted in to our mailing list.<br>
    <a href="'.e($unsubscribeUrl).'" style="color:#6b7280;text-decoration:underline;" target="_blank">Unsubscribe</a>
</div>';
    }

    /**
     * Write the outbound broadcast into the inbox tables (conversations + messages)
     * so agents see the broadcast alongside normal chat and any reply threads into
     * the same conversation.
     *
     * @param  array{id: string, body: string, type: string, payload: array<string, mixed>}  $sent
     */
    private function syncToInbox(Campaign $campaign, Contact $contact, array $sent): void
    {
        try {
            $channelAccount = $this->resolveChannelAccount($campaign);

            $externalThreadId = match ($campaign->channel) {
                'whatsapp' => ltrim((string) ($contact->phone_e164 ?? ''), '+'),
                'sms' => (string) ($contact->phone_e164 ?? ''),
                'email' => (string) ($contact->email ?? ''),
                default => null,
            };

            $conversation = Conversation::firstOrCreate(
                [
                    'workspace_id' => $campaign->workspace_id,
                    'contact_id' => $contact->id,
                    'channel_account_id' => $channelAccount?->id,
                ],
                [
                    'status' => 'open',
                    'external_thread_id' => $externalThreadId,
                ],
            );

            $sentAt = now();

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'channel' => $campaign->channel,
                'type' => $sent['type'],
                'payload' => $sent['payload'],
                'body' => $sent['body'],
                'status' => 'sent',
                'provider_message_id' => $sent['id'] ?: null,
                'sent_by' => 'broadcast',
                'sent_at' => $sentAt,
            ]);

            $conversation->update(['last_message_at' => $sentAt]);

            MessageSent::dispatch($message);
        } catch (\Throwable $e) {
            // Never let inbox mirroring fail the campaign send — the send
            // already succeeded at the provider. Log and move on.
            Log::channel('json')->warning('campaign.inbox.sync_failed', [
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'channel' => $campaign->channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveChannelAccount(Campaign $campaign): ?ChannelAccount
    {
        if ($campaign->channel === 'whatsapp') {
            $client = CloudApiClient::forWorkspace($campaign->workspace_id);
            $phoneNumberId = $client?->phoneNumberId();
            if ($phoneNumberId !== null && $phoneNumberId !== '') {
                $match = ChannelAccount::where('workspace_id', $campaign->workspace_id)
                    ->where('channel', 'whatsapp')
                    ->where('phone_number_id', $phoneNumberId)
                    ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                    ->orderBy('id')
                    ->first();
                if ($match) {
                    return $match;
                }
            }
        }

        return ChannelAccount::where('workspace_id', $campaign->workspace_id)
            ->where('channel', $campaign->channel)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }

    /**
     * Produce a human-readable one-liner for a WhatsApp template send so the
     * inbox conversation list shows something meaningful.
     *
     * Prefers the resolved BODY text from the canonical definition (with `{{N}}`
     * placeholders substituted), falling back to the first body parameter, then
     * to a `[template: name]` label.
     *
     * @param  array<int, mixed>       $components
     * @param  array<int, mixed>|null  $definition
     */
    private function summariseTemplateForInbox(string $templateName, array $components, ?array $definition = null): string
    {
        // 1. Resolve the canonical BODY text with its parameters.
        foreach ($definition ?? [] as $component) {
            if (! is_array($component) || strtoupper((string) ($component['type'] ?? '')) !== 'BODY') {
                continue;
            }
            $text = (string) ($component['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $params = (array) ($component['parameters'] ?? []);

            return preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', function (array $m) use ($params) {
                $param = $params[((int) $m[1]) - 1] ?? null;

                return is_array($param) && isset($param['text']) ? (string) $param['text'] : $m[0];
            }, $text);
        }

        // 2. Fall back to the first body parameter value.
        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }
            $type = strtolower((string) ($component['type'] ?? ''));
            if ($type !== 'body') {
                continue;
            }
            foreach ((array) ($component['parameters'] ?? []) as $param) {
                if (is_array($param) && isset($param['text']) && is_string($param['text']) && $param['text'] !== '') {
                    return $param['text'];
                }
            }
        }

        return '[template: '.$templateName.']';
    }
}
