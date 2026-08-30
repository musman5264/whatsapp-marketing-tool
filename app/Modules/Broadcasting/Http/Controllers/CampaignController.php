<?php

namespace App\Modules\Broadcasting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Broadcasting\Jobs\LaunchCampaignJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Broadcasting\Services\CampaignPersonalizer;
use App\Modules\Broadcasting\Services\Sms\SmsDriverManager;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\SegmentResolver;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $campaigns = Campaign::where('workspace_id', $workspaceId)
            ->when($request->channel, fn ($q) => $q->where('channel', $request->channel))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Broadcasting/Campaigns/Index', [
            'campaigns' => $campaigns,
            'filters' => $request->only('channel', 'status'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Broadcasting/Campaigns/Wizard', $this->wizardProps($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);
        $validated = $this->validateCampaign($request);

        $campaign = Campaign::create(array_merge($validated, [
            'workspace_id' => $workspaceId,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]));

        return redirect()->route('client.campaigns.show', $campaign)->with('success', 'Campaign created.');
    }

    /**
     * POST /campaigns/draft
     * Saves (or updates) a minimal draft after step 1 of the wizard.
     * Returns JSON so the wizard can stay on the page and continue.
     */
    /**
     * POST /campaigns/draft
     * Upserts a draft campaign with whatever fields are available at the current wizard step.
     * Returns JSON so the wizard can stay on the page without a full redirect.
     */
    public function storeDraft(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        $validated = $request->validate([
            'uuid'                      => ['nullable', 'string', 'uuid'],
            'name'                      => ['required', 'string', 'max:128'],
            'channel'                   => ['required', 'in:whatsapp,sms,email'],
            'whatsapp_phone_number_id'  => ['nullable', 'string'],
            'audience_type'             => ['nullable', 'in:segment,contact_list,tag,csv'],
            'audience_ref'              => ['nullable', 'string'],
            'template_ref'              => ['nullable', 'array'],
            'payload_json'              => ['nullable', 'array'],
            'schedule_at'               => ['nullable', 'date'],
            'timezone'                  => ['nullable', 'string', 'max:64'],
        ]);

        $fields = array_filter([
            'name'                     => $validated['name'],
            'channel'                  => $validated['channel'],
            'whatsapp_phone_number_id' => $validated['whatsapp_phone_number_id'] ?? null,
            'audience_type'            => $validated['audience_type'] ?? null,
            'audience_ref'             => $validated['audience_ref'] ?? null,
            'template_ref'             => $validated['template_ref'] ?? null,
            'payload_json'             => $validated['payload_json'] ?? null,
            'schedule_at'              => $validated['schedule_at'] ?? null,
            'timezone'                 => $validated['timezone'] ?? null,
        ], fn ($v) => $v !== null);

        if (! empty($validated['uuid'])) {
            $existing = Campaign::where('workspace_id', $workspaceId)
                ->where('uuid', $validated['uuid'])
                ->where('status', 'draft')
                ->first();

            if ($existing) {
                $existing->update($fields);
                return response()->json(['uuid' => $existing->uuid]);
            }
        }

        $campaign = Campaign::create(array_merge($fields, [
            'workspace_id'  => $workspaceId,
            'audience_type' => $fields['audience_type'] ?? 'segment',
            'status'        => 'draft',
            'created_by'    => $request->user()->id,
        ]));

        return response()->json(['uuid' => $campaign->uuid]);
    }

    public function edit(Request $request, Campaign $campaign): Response
    {
        $this->authorise($request, $campaign);
        abort_unless(in_array($campaign->status, ['draft', 'paused'], true), 422, 'Only drafts or paused campaigns can be edited.');

        return Inertia::render('Broadcasting/Campaigns/Edit', array_merge(
            $this->wizardProps($request),
            ['campaign' => $campaign->only(
                'id', 'uuid', 'name', 'channel', 'whatsapp_phone_number_id', 'audience_type', 'audience_ref',
                'template_ref', 'payload_json', 'schedule_at', 'timezone', 'status',
            )],
        ));
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorise($request, $campaign);
        abort_unless(in_array($campaign->status, ['draft', 'paused'], true), 422, 'Only drafts or paused campaigns can be edited.');

        $validated = $this->validateCampaign($request);
        $campaign->update($validated);

        return redirect()->route('client.campaigns.show', $campaign)->with('success', 'Campaign updated.');
    }

    public function show(Request $request, Campaign $campaign): Response
    {
        $this->authorise($request, $campaign);

        // Only recalculate totals for campaigns that are still changing.
        // Completed/failed/draft campaigns have stable totals stored in totals_json.
        if (in_array($campaign->status, ['queued', 'sending', 'paused'], true)) {
            $campaign->updateTotals();
            $campaign->refresh();
        }

        $campaign->loadCount('recipients');

        $recipientStats = CampaignRecipient::where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $sample = CampaignRecipient::where('campaign_id', $campaign->id)
            ->with(['contact:id,first_name,last_name,phone_e164,email'])
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        return Inertia::render('Broadcasting/Campaigns/Show', [
            'campaign' => $campaign,
            'stats' => $recipientStats,
            'sample' => $sample,
            'reportUrl' => route('client.reports.campaigns.show', $campaign->uuid),
        ]);
    }

    public function launch(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorise($request, $campaign);
        abort_unless(in_array($campaign->status, ['draft', 'paused'], true), 422, 'Cannot launch this campaign.');

        $patch = ['status' => 'queued'];

        // Only override schedule_at if the request explicitly carries one.
        // Sending an empty string explicitly means "send immediately now".
        if ($request->has('schedule_at')) {
            $value = $request->input('schedule_at');
            $patch['schedule_at'] = filled($value) ? $value : null;
        }

        if ($campaign->channel === 'whatsapp') {
            $this->assertWhatsAppCampaignReady($campaign);
        }

        $campaign->update($patch);
        $campaign->refresh();

        // Only kick the job immediately when there is no future schedule.
        // Future-scheduled campaigns are picked up by LaunchScheduledCampaignsJob.
        if (! $campaign->schedule_at || $campaign->schedule_at->isPast()) {
            LaunchCampaignJob::dispatch($campaign->id)->onQueue('broadcast');
        }

        UsageMeter::track($campaign->workspace_id, 'campaigns');

        return back()->with('success', 'Campaign launched.');
    }

    public function pause(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorise($request, $campaign);
        abort_unless(in_array($campaign->status, ['queued', 'sending'], true), 422, 'Only queued or sending campaigns can be paused.');
        $campaign->update(['status' => 'paused']);

        return back()->with('success', 'Campaign paused.');
    }

    public function destroy(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorise($request, $campaign);
        abort_unless($campaign->status === 'draft', 422, 'Only draft campaigns can be deleted.');
        $campaign->delete();

        return redirect()->route('client.campaigns.index')->with('success', 'Campaign deleted.');
    }

    /**
     * POST /campaigns/audience-preview
     * Returns the matching contact count for an audience selection.
     */
    public function audiencePreview(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);

        $validated = $request->validate([
            'audience_type' => ['required', 'in:segment,contact_list,tag,csv'],
            'audience_ref' => ['nullable', 'string'],
            'channel' => ['required', 'in:whatsapp,sms,email'],
        ]);

        $contactIds = $this->resolveAudienceForPreview(
            $workspaceId,
            $validated['audience_type'],
            $validated['audience_ref'] ?? null,
        );

        $totalMatched = count($contactIds);

        $optInColumn = match ($validated['channel']) {
            'whatsapp' => 'opt_in_whatsapp',
            'sms' => 'opt_in_sms',
            'email' => 'opt_in_email',
        };

        $deliverable = 0;
        $sample = [];

        if ($totalMatched > 0) {
            $query = Contact::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $contactIds)
                ->where($optInColumn, true);

            if ($validated['channel'] === 'email') {
                $query->whereNotNull('email')->where('email', '!=', '');
            } else {
                $query->whereNotNull('phone_e164')->where('phone_e164', '!=', '');
            }

            $deliverable = $query->count();
            $sample = $query->limit(5)
                ->get(['id', 'first_name', 'last_name', 'phone_e164', 'email']);
        }

        return response()->json([
            'matched' => $totalMatched,
            'deliverable' => $deliverable,
            'sample' => $sample,
        ]);
    }

    /**
     * POST /campaigns/{campaign}/test-send
     * Sends a one-off message to a single phone/email using this campaign's content.
     */
    public function testSend(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorise($request, $campaign);

        $validated = $request->validate([
            'phone_e164' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        if (empty($validated['phone_e164']) && empty($validated['email'])) {
            return response()->json(['error' => 'Provide either a phone or email to test.'], 422);
        }

        // Strip whitespace/dashes — pass the number through as-is so each SMS
        // driver can normalise for its own provider (some BD providers expect
        // local format 01XXXXXXXXX, not the +880… international prefix).
        if (! empty($validated['phone_e164'])) {
            $validated['phone_e164'] = preg_replace('/[\s\-()]/', '', $validated['phone_e164']);
        }

        $personalizer = app(CampaignPersonalizer::class);
        $user = $request->user();

        // Build a synthetic Contact with the user's data so personalization tokens render meaningfully.
        $contact = new Contact([
            'workspace_id' => $campaign->workspace_id,
            'phone_e164' => $validated['phone_e164'] ?? null,
            'email' => $validated['email'] ?? null,
            'first_name' => $user->name ? explode(' ', $user->name)[0] : 'Test',
            'last_name' => $user->name && str_contains($user->name, ' ')
                ? trim(substr($user->name, strpos($user->name, ' ') + 1))
                : 'User',
            'opt_in_whatsapp' => true,
            'opt_in_sms' => true,
            'opt_in_email' => true,
        ]);

        try {
            $messageId = match ($campaign->channel) {
                'whatsapp' => $this->testSendWhatsApp($campaign, $contact, $personalizer),
                'sms' => $this->testSendSms($campaign, $contact, $personalizer),
                'email' => $this->testSendEmail($campaign, $contact, $personalizer),
            };

            return response()->json([
                'ok' => true,
                'message_id' => $messageId,
                'channel' => $campaign->channel,
            ]);
        } catch (\Throwable $e) {
            // Log full details server-side; return a sanitised message to the client
            // so SMTP credentials, API keys, and internal paths are not disclosed.
            \Illuminate\Support\Facades\Log::channel('json')->warning('campaign.test_send.failed', [
                'campaign_id' => $campaign->id,
                'channel'     => $campaign->channel,
                'error'       => $e->getMessage(),
            ]);

            $safe = match (true) {
                str_contains($e->getMessage(), 'No WhatsApp') => $e->getMessage(),
                str_contains($e->getMessage(), 'Pick a WhatsApp') => $e->getMessage(),
                str_contains($e->getMessage(), 'Phone is required') => $e->getMessage(),
                str_contains($e->getMessage(), 'Email is required') => $e->getMessage(),
                str_contains($e->getMessage(), 'SMS body is empty') => $e->getMessage(),
                str_contains($e->getMessage(), 'empty after personalization') => $e->getMessage(),
                default => 'Send failed. Check your channel configuration and try again.',
            };

            return response()->json(['error' => $safe], 500);
        }
    }

    private function authorise(Request $request, Campaign $campaign): void
    {
        $workspaceId = $this->workspaceId($request);
        abort_unless((int) $campaign->workspace_id === (int) $workspaceId, 403);
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    private function validateCampaign(Request $request): array
    {
        return $request->validate([
            'name'                     => ['required', 'string', 'max:128'],
            'channel'                  => ['required', 'in:whatsapp,sms,email'],
            'whatsapp_phone_number_id' => ['nullable', 'string'],
            'audience_type'            => ['required', 'in:segment,contact_list,tag,csv'],
            'audience_ref'             => ['nullable', 'string'],
            'template_ref'             => ['nullable', 'array'],
            'payload_json'             => ['nullable', 'array'],
            'schedule_at'              => ['nullable', 'date'],
            'timezone'                 => ['nullable', 'string', 'max:64'],
        ]);
    }

    /**
     * Build the props the wizard / edit page need.
     */
    private function assertWhatsAppCampaignReady(Campaign $campaign): void
    {
        $client = $campaign->whatsapp_phone_number_id
            ? CloudApiClient::forPhoneNumber($campaign->whatsapp_phone_number_id, $campaign->workspace_id)
            : CloudApiClient::forWorkspace($campaign->workspace_id);

        if (! $client) {
            abort(422, 'WhatsApp is not ready: connect a WABA on Channel Setup and sync at least one phone number.');
        }

        $tpl = $campaign->template_ref ?? [];
        $name = $tpl['name'] ?? '';
        if ($name === '') {
            abort(422, 'Select an approved WhatsApp template before launching.');
        }

        $approved = WhatsappTemplate::where('workspace_id', $campaign->workspace_id)
            ->where('name', $name)
            ->where('language', $tpl['language'] ?? 'en')
            ->where('status', 'APPROVED')
            ->exists();

        if (! $approved) {
            abort(422, 'Template "'.$name.'" is not APPROVED. Sync templates from Meta on the Templates page, then try again.');
        }
    }

    private function wizardProps(Request $request): array
    {
        $workspaceId = $this->workspaceId($request);

        $whatsappTemplates = WhatsappTemplate::where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->orderBy('language')
            ->get(['id', 'waba_id', 'name', 'language', 'status', 'category', 'components'])
            ->sortBy(fn ($t) => match ($t->status) {
                'APPROVED' => 0,
                'PENDING' => 1,
                'PAUSED' => 2,
                default => 3,
            })
            ->values();

        $segments = Segment::where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'contact_count']);

        $tags = ContactTag::where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        $whatsappPhoneNumbers = WhatsappBusinessAccount::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->with('phoneNumbers')
            ->get()
            ->flatMap(fn ($waba) => $waba->phoneNumbers->map(fn ($p) => [
                'phone_number_id' => $p->phone_number_id,
                'display_phone'   => $p->display_phone,
                'verified_name'   => $p->verified_name,
                'waba_id'         => $waba->waba_id,
            ]))
            ->values();

        return [
            'whatsappTemplates'    => $whatsappTemplates,
            'whatsappPhoneNumbers' => $whatsappPhoneNumbers,
            'segments'             => $segments,
            'tags'                 => $tags,
            'contactTokens'        => CampaignPersonalizer::availableContactTokens(),
        ];
    }

    /**
     * Mirror of the audience resolution used by LaunchCampaignJob, scoped to a single workspace.
     *
     * @return array<int, int>
     */
    private function resolveAudienceForPreview(int $workspaceId, string $type, ?string $ref): array
    {
        return match ($type) {
            'segment' => $this->resolveSegmentForPreview($workspaceId, $ref),
            'tag' => $this->resolveTagForPreview($workspaceId, $ref),
            'contact_list' => Contact::where('workspace_id', $workspaceId)->pluck('id')->all(),
            'csv' => [],
            default => [],
        };
    }

    /** @return array<int, int> */
    private function resolveSegmentForPreview(int $workspaceId, ?string $ref): array
    {
        if (! $ref) {
            return [];
        }
        $segment = Segment::where('workspace_id', $workspaceId)->find($ref);
        if (! $segment) {
            return [];
        }
        if ($segment->type === 'static') {
            return $segment->contacts()->pluck('contacts.id')->all();
        }

        return app(SegmentResolver::class)->query($segment)->pluck('id')->all();
    }

    /** @return array<int, int> */
    private function resolveTagForPreview(int $workspaceId, ?string $ref): array
    {
        if (! $ref) {
            return [];
        }

        return Contact::where('workspace_id', $workspaceId)
            ->whereHas('tags', fn ($q) => $q->where('contact_tags.id', $ref))
            ->pluck('id')
            ->all();
    }

    private function testSendWhatsApp(Campaign $campaign, Contact $contact, CampaignPersonalizer $personalizer): string
    {
        if (empty($contact->phone_e164)) {
            throw new \RuntimeException('Phone is required for a WhatsApp test send.');
        }

        $client = $campaign->whatsapp_phone_number_id
            ? CloudApiClient::forPhoneNumber($campaign->whatsapp_phone_number_id, $campaign->workspace_id)
            : CloudApiClient::forWorkspace($campaign->workspace_id);
        if (! $client) {
            throw new \RuntimeException('No WhatsApp client configured for this workspace.');
        }

        $tpl = $campaign->template_ref ?? [];
        $name = $tpl['name'] ?? '';
        $language = $tpl['language'] ?? 'en';
        $components = is_array($tpl['components'] ?? null) ? $tpl['components'] : [];

        if ($name === '') {
            throw new \RuntimeException('Pick a WhatsApp template before sending a test.');
        }

        $phone = $contact->phone_e164;
        if (! str_starts_with($phone, '+')) {
            $phone = '+'.$phone;
        }

        $rendered = $personalizer->renderTemplateComponents($components, $contact);
        $resp = $client->sendTemplate($phone, $name, $language, $rendered);

        if (! $resp->successful()) {
            throw new \RuntimeException('WhatsApp send failed: '.$resp->body());
        }

        return $resp->json('messages.0.id', '');
    }

    private function testSendSms(Campaign $campaign, Contact $contact, CampaignPersonalizer $personalizer): string
    {
        if (empty($contact->phone_e164)) {
            throw new \RuntimeException('Phone is required for an SMS test send.');
        }

        $body = $personalizer->renderText($campaign->payload_json['body'] ?? '', $contact);
        if (trim($body) === '') {
            throw new \RuntimeException('SMS body is empty after personalization.');
        }

        $driver = SmsDriverManager::forWorkspace($campaign->workspace_id);
        $result = $driver->send($contact->phone_e164, $body);

        if (! $result->success) {
            throw new \RuntimeException($result->error);
        }

        return $result->messageId;
    }

    private function testSendEmail(Campaign $campaign, Contact $contact, CampaignPersonalizer $personalizer): string
    {
        if (empty($contact->email)) {
            throw new \RuntimeException('Email is required for an email test send.');
        }

        $payload   = $campaign->payload_json ?? [];
        $subject   = $personalizer->renderText('[TEST] '.($payload['subject'] ?? 'No subject'), $contact);
        $body      = $personalizer->renderText($payload['body'] ?? '', $contact);
        $fromEmail = filled($payload['from_email'] ?? '') ? $payload['from_email'] : null;
        $fromName  = filled($payload['from_name']  ?? '') ? $payload['from_name']  : null;
        $replyTo   = filled($payload['reply_to']   ?? '') ? $payload['reply_to']   : null;

        $smtp = \App\Modules\Broadcasting\Models\WorkspaceSmtpConfig::forWorkspace($campaign->workspace_id)
            ?? \App\Models\SmtpConfiguration::getActive();

        if ($smtp) {
            app(\App\Services\Mail\MailService::class)->sendRaw(
                $smtp, $contact->email, $subject, $body, [], $fromEmail, $fromName, $replyTo
            );
        } else {
            Mail::html($body, function ($m) use ($contact, $subject, $fromEmail, $fromName, $replyTo) {
                $m->to($contact->email, $contact->full_name)->subject($subject);
                if ($fromEmail) {
                    $m->from($fromEmail, $fromName ?: null);
                }
                if ($replyTo) {
                    $m->replyTo($replyTo);
                }
            });
        }

        return 'email-test:'.uniqid();
    }
}
