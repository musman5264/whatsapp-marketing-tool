<?php

namespace App\Modules\Broadcasting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SmsProviderController extends Controller
{
    public const PROVIDERS = ['twilio', 'nexmo', 'messagebird', 'plivo', 'telnyx', 'infobip', 'clicksend', 'smsbd', 'reve', 'bulksmsbd', 'sms_dot_bd', 'mimsms', 'fast2sms', 'msg91', 'amazon_sns'];

    public const FIELDS = [
        'twilio' => [
            ['key' => 'account_sid', 'label' => 'Account SID',  'type' => 'text',     'required' => true],
            ['key' => 'auth_token',  'label' => 'Auth Token',    'type' => 'password', 'required' => true],
            ['key' => 'from_number', 'label' => 'From Number',   'type' => 'text',     'required' => false],
        ],
        'nexmo' => [
            ['key' => 'api_key',    'label' => 'API Key',    'type' => 'text',     'required' => true],
            ['key' => 'api_secret', 'label' => 'API Secret', 'type' => 'password', 'required' => true],
            ['key' => 'from',       'label' => 'From Name',  'type' => 'text',     'required' => false],
        ],
        'messagebird' => [
            ['key' => 'api_key',    'label' => 'API Key',    'type' => 'password', 'required' => true],
            ['key' => 'originator', 'label' => 'Originator', 'type' => 'text',     'required' => false],
        ],
        'plivo' => [
            ['key' => 'auth_id',    'label' => 'Auth ID',        'type' => 'text',     'required' => true],
            ['key' => 'auth_token', 'label' => 'Auth Token',     'type' => 'password', 'required' => true],
            ['key' => 'from',       'label' => 'From (Sender/Number)', 'type' => 'text', 'required' => false],
        ],
        'telnyx' => [
            ['key' => 'api_key',              'label' => 'API Key (V2)',           'type' => 'password', 'required' => true],
            ['key' => 'from',                 'label' => 'From Number',            'type' => 'text',     'required' => false],
            ['key' => 'messaging_profile_id', 'label' => 'Messaging Profile ID',   'type' => 'text',     'required' => false],
        ],
        'infobip' => [
            ['key' => 'api_key',  'label' => 'API Key',                       'type' => 'password', 'required' => true],
            ['key' => 'base_url', 'label' => 'Base URL (e.g. xyz.api.infobip.com)', 'type' => 'text', 'required' => true],
            ['key' => 'from',     'label' => 'Sender',                        'type' => 'text',     'required' => false],
        ],
        'clicksend' => [
            ['key' => 'username', 'label' => 'Username',    'type' => 'text',     'required' => true],
            ['key' => 'api_key',  'label' => 'API Key',     'type' => 'password', 'required' => true],
            ['key' => 'from',     'label' => 'From',        'type' => 'text',     'required' => false],
        ],
        'smsbd' => [
            ['key' => 'api_key', 'label' => 'API Key',   'type' => 'password', 'required' => true],
            ['key' => 'sender',  'label' => 'Sender ID', 'type' => 'text',     'required' => false],
        ],
        'reve' => [
            ['key' => 'api_key',    'label' => 'API Key',     'type' => 'password', 'required' => true],
            ['key' => 'api_secret', 'label' => 'API Secret',  'type' => 'password', 'required' => true],
            ['key' => 'mask',       'label' => 'Sender Mask', 'type' => 'text',     'required' => false],
        ],
        'bulksmsbd' => [
            ['key' => 'api_key',   'label' => 'API Key',   'type' => 'password', 'required' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text',     'required' => false],
        ],
        'sms_dot_bd' => [
            ['key' => 'api_key',   'label' => 'API Key',   'type' => 'password', 'required' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text',     'required' => false],
        ],
        'mimsms' => [
            ['key' => 'api_key',   'label' => 'API Key',   'type' => 'password', 'required' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text',     'required' => false],
        ],
        'fast2sms' => [
            ['key' => 'api_key',   'label' => 'API Key',         'type' => 'password', 'required' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID',       'type' => 'text',     'required' => false],
            ['key' => 'route',     'label' => 'Route (q / dlt)', 'type' => 'text',     'required' => false],
        ],
        'msg91' => [
            ['key' => 'auth_key',  'label' => 'Auth Key',                     'type' => 'password', 'required' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID (6 chars)',          'type' => 'text',     'required' => false],
            ['key' => 'route',     'label' => 'Route (4 = Transactional / 1 = Promotional)', 'type' => 'text', 'required' => false],
            ['key' => 'dlt_te_id', 'label' => 'DLT Template ID (India only)', 'type' => 'text',     'required' => false],
        ],
        'amazon_sns' => [
            ['key' => 'access_key', 'label' => 'AWS Access Key ID',     'type' => 'text',     'required' => true],
            ['key' => 'secret_key', 'label' => 'AWS Secret Access Key', 'type' => 'password', 'required' => true],
            ['key' => 'region',     'label' => 'AWS Region',            'type' => 'text',     'required' => true],
            ['key' => 'sender_id',  'label' => 'Sender ID',             'type' => 'text',     'required' => false],
            ['key' => 'sms_type',   'label' => 'SMS Type (Transactional / Promotional)', 'type' => 'text', 'required' => false],
        ],
    ];

    public const LABELS = [
        'twilio'      => 'Twilio',
        'nexmo'       => 'Vonage / Nexmo',
        'messagebird' => 'MessageBird',
        'plivo'       => 'Plivo',
        'telnyx'      => 'Telnyx',
        'infobip'     => 'Infobip',
        'clicksend'   => 'ClickSend',
        'smsbd'       => 'SMSBD',
        'reve'        => 'REVE SMS',
        'bulksmsbd'   => 'BulkSMS BD',
        'sms_dot_bd'  => 'SMS.BD (sms.net.bd)',
        'mimsms'      => 'MimSMS',
        'fast2sms'    => 'Fast2SMS',
        'msg91'       => 'MSG91',
        'amazon_sns'  => 'Amazon SNS',
    ];

    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $configs = SmsProviderConfig::where('workspace_id', $workspaceId)->get()->keyBy('provider');

        $providers = collect(self::PROVIDERS)->map(fn ($p) => [
            'provider'   => $p,
            'label'      => self::LABELS[$p],
            'fields'     => self::FIELDS[$p],
            'configured' => $configs->has($p) && ! empty($configs->get($p)->credentials),
            'default'    => $configs->get($p)?->default ?? false,
            'sender_id'  => $configs->get($p)?->sender_id ?? '',
            'masked'     => $configs->has($p) ? $this->mask($configs->get($p)->credentials ?? []) : [],
        ]);

        return Inertia::render('Broadcasting/SmsProviders/Index', [
            'providers' => $providers,
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        $workspaceId = $this->workspaceId($request);
        $fields = self::FIELDS[$provider];

        $rules = ['sender_id' => ['nullable', 'string', 'max:64'], 'default' => ['boolean']];
        foreach ($fields as $f) {
            $rules['credentials.'.$f['key']] = ['nullable', 'string', 'max:512'];
        }

        $validated = $request->validate($rules);

        $config = SmsProviderConfig::firstOrNew(['workspace_id' => $workspaceId, 'provider' => $provider]);
        $existing = $config->credentials ?? [];
        $incoming = $validated['credentials'] ?? [];
        $merged = $existing;

        foreach ($incoming as $k => $v) {
            if ($v === null || $v === '' || preg_match('/^•+/', (string) $v)) {
                continue;
            }
            $merged[$k] = $v;
        }

        if ($validated['default'] ?? false) {
            SmsProviderConfig::where('workspace_id', $workspaceId)
                ->where('provider', '!=', $provider)
                ->update(['default' => false]);
        }

        $config->fill([
            'credentials' => $merged,
            'sender_id'   => $validated['sender_id'] ?? $config->sender_id,
            'default'     => (bool) ($validated['default'] ?? false),
        ])->save();

        return back()->with('success', self::LABELS[$provider].' configuration saved.');
    }

    public function destroy(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        $workspaceId = $this->workspaceId($request);
        SmsProviderConfig::where('workspace_id', $workspaceId)->where('provider', $provider)->delete();

        return back()->with('success', self::LABELS[$provider].' configuration removed.');
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    private function mask(array $creds): array
    {
        return collect($creds)->map(fn ($v) => $v === '' ? '' : '••••••••••••')->all();
    }
}
