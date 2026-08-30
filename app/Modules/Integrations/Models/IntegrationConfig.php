<?php

namespace App\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationConfig extends Model
{
    // All valid provider slugs
    public const PROVIDERS = [
        'meta_app',
        'oauth_linkedin',
        'oauth_twitter',
        'oauth_youtube',
        'oauth_tiktok',
        'oauth_shopify',
        'oauth_bigcommerce',
        'llm_openai_default',
        'llm_anthropic_default',
        'llm_gemini_default',
        'google_places',
        'google_workspace',
        'qdrant',
        'whatsapp_web',
        'storage_local',
        'storage_s3',
        'storage_do',
        'storage_wasabi',
    ];

    /** The single provider slug that is the active storage backend. */
    public const STORAGE_PROVIDERS = ['storage_local', 'storage_s3', 'storage_do', 'storage_wasabi'];

    /** Maps provider slug → Laravel disk name. */
    public const STORAGE_DISK_MAP = [
        'storage_local' => 'public',
        'storage_s3' => 's3',
        'storage_do' => 'do_spaces',
        'storage_wasabi' => 'wasabi',
    ];

    // Human-readable labels per provider
    public const LABELS = [
        'meta_app' => 'Meta App (WhatsApp / Instagram / Messenger / Facebook)',
        'oauth_linkedin' => 'LinkedIn OAuth',
        'oauth_twitter' => 'Twitter / X OAuth',
        'oauth_youtube' => 'YouTube / Google OAuth',
        'oauth_tiktok' => 'TikTok OAuth',
        'oauth_shopify' => 'Shopify App (OAuth)',
        'oauth_bigcommerce' => 'BigCommerce App (OAuth)',
        'llm_openai_default' => 'OpenAI (Default)',
        'llm_anthropic_default' => 'Anthropic Claude (Default)',
        'llm_gemini_default' => 'Google Gemini (Default)',
        'google_places' => 'Google Places API',
        'google_workspace' => 'Google Workspace (Sheets / Docs / Calendar / Meet)',
        'qdrant' => 'Qdrant Vector Store',
        'whatsapp_web' => 'WhatsApp Web (QR / unofficial)',
        'storage_local' => 'Local Storage (server disk)',
        'storage_s3' => 'Amazon S3',
        'storage_do' => 'DigitalOcean Spaces',
        'storage_wasabi' => 'Wasabi Cloud Storage',
    ];

    // Which category each provider belongs to (for UI grouping)
    public const CATEGORIES = [
        'meta_app' => 'Meta',
        'oauth_linkedin' => 'Social OAuth',
        'oauth_twitter' => 'Social OAuth',
        'oauth_youtube' => 'Social OAuth',
        'oauth_tiktok' => 'Social OAuth',
        'oauth_shopify' => 'E-Commerce OAuth',
        'oauth_bigcommerce' => 'E-Commerce OAuth',
        'llm_openai_default' => 'AI / LLM',
        'llm_anthropic_default' => 'AI / LLM',
        'llm_gemini_default' => 'AI / LLM',
        'google_places' => 'Maps',
        'google_workspace' => 'Google Workspace',
        'qdrant' => 'Vector Store',
        'whatsapp_web' => 'Messaging',
        'storage_local' => 'Storage',
        'storage_s3' => 'Storage',
        'storage_do' => 'Storage',
        'storage_wasabi' => 'Storage',
    ];

    // Field definitions per provider (used to build dynamic forms)
    public const FIELDS = [
        'meta_app' => [
            ['key' => 'app_id',              'label' => 'App ID',                               'type' => 'text',     'required' => true],
            ['key' => 'app_secret',          'label' => 'App Secret',                           'type' => 'password', 'required' => true],
            ['key' => 'system_user_token',   'label' => 'System User Access Token',             'type' => 'password', 'required' => false],
            ['key' => 'verify_token',        'label' => 'Webhook Verify Token',                 'type' => 'text',     'required' => false],
            ['key' => 'config_id_whatsapp',  'label' => 'Embedded Signup Config ID (WhatsApp)', 'type' => 'text',     'required' => false, 'hint' => 'From Meta App Dashboard → Facebook Login for Business → WhatsApp Embedded Signup configuration'],
            ['key' => 'config_id_social',    'label' => 'Embedded Signup Config ID (Instagram / Messenger / Social Posting)', 'type' => 'text', 'required' => false, 'hint' => 'From Meta App Dashboard → Facebook Login for Business → Social Embedded Signup configuration. Also used for Social Media account connect — include pages_show_list, pages_read_engagement, pages_manage_posts, instagram_basic and instagram_content_publish in this configuration.'],
        ],
        'oauth_linkedin' => [
            ['key' => 'client_id',     'label' => 'Client ID',     'type' => 'text',     'required' => true],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
        ],
        'oauth_twitter' => [
            ['key' => 'client_id',     'label' => 'Client ID',     'type' => 'text',     'required' => true],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
        ],
        'oauth_youtube' => [
            ['key' => 'client_id',     'label' => 'Client ID',     'type' => 'text',     'required' => true],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
        ],
        'oauth_tiktok' => [
            ['key' => 'client_key',    'label' => 'Client Key',    'type' => 'text',     'required' => true],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
        ],
        'oauth_shopify' => [
            ['key' => 'client_id',     'label' => 'API Key (Client ID)',        'type' => 'text',     'required' => true,  'hint' => 'From your Shopify Partner app → Client credentials'],
            ['key' => 'client_secret', 'label' => 'API Secret Key (Client Secret)', 'type' => 'password', 'required' => true],
        ],
        'oauth_bigcommerce' => [
            ['key' => 'client_id',     'label' => 'Client ID',     'type' => 'text',     'required' => true,  'hint' => 'From your BigCommerce Dev Portal app'],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password', 'required' => true],
        ],
        'llm_openai_default' => [
            ['key' => 'api_key',        'label' => 'API Key',        'type' => 'password', 'required' => true],
            ['key' => 'organization_id', 'label' => 'Organization ID', 'type' => 'text',     'required' => false],
        ],
        'llm_anthropic_default' => [
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
        ],
        'llm_gemini_default' => [
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
        ],
        'google_places' => [
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true],
        ],
        'google_workspace' => [
            ['key' => 'client_id',     'label' => 'OAuth Client ID',     'type' => 'text',     'required' => true,  'hint' => 'Google Cloud Console → APIs & Services → Credentials → OAuth client (Web).'],
            ['key' => 'client_secret', 'label' => 'OAuth Client Secret', 'type' => 'password', 'required' => true],
            ['key' => 'refresh_token', 'label' => 'Refresh Token',       'type' => 'password', 'required' => true,  'hint' => 'Offline-access refresh token with Sheets, Docs, Drive, Calendar & Forms scopes (e.g. via the OAuth Playground).'],
        ],
        'qdrant' => [
            ['key' => 'url',     'label' => 'Qdrant URL',   'type' => 'text',     'required' => true],
            ['key' => 'api_key', 'label' => 'API Key',       'type' => 'password', 'required' => false],
        ],

        'whatsapp_web' => [
            ['key' => 'engine',   'label' => 'Engine',    'type' => 'text',     'required' => true,  'hint' => 'waha (the only supported engine)'],
            ['key' => 'base_url', 'label' => 'WAHA URL',  'type' => 'text',     'required' => true,  'hint' => 'e.g. http://waha:3000 (reachable from this server)'],
            ['key' => 'api_key',  'label' => 'WAHA API Key', 'type' => 'password', 'required' => false, 'hint' => 'The WAHA_API_KEY set on the WAHA container, if any'],
        ],

        'storage_local' => [
            // No credentials required — uses server disk
        ],

        'storage_s3' => [
            ['key' => 'key',                    'label' => 'Access Key ID',          'type' => 'text',     'required' => true],
            ['key' => 'secret',                 'label' => 'Secret Access Key',      'type' => 'password', 'required' => true],
            ['key' => 'region',                 'label' => 'Region',                 'type' => 'text',     'required' => true],
            ['key' => 'bucket',                 'label' => 'Bucket Name',            'type' => 'text',     'required' => true],
            ['key' => 'url',                    'label' => 'Custom URL (optional)',   'type' => 'text',     'required' => false],
            ['key' => 'directory_prefix',       'label' => 'Directory Prefix',       'type' => 'text',     'required' => false],
        ],

        'storage_do' => [
            ['key' => 'key',                    'label' => 'Spaces Access Key',      'type' => 'text',     'required' => true],
            ['key' => 'secret',                 'label' => 'Spaces Secret Key',      'type' => 'password', 'required' => true],
            ['key' => 'region',                 'label' => 'Region (e.g. nyc3)',     'type' => 'text',     'required' => true],
            ['key' => 'bucket',                 'label' => 'Space Name (bucket)',    'type' => 'text',     'required' => true],
            ['key' => 'endpoint',               'label' => 'Endpoint URL',           'type' => 'text',     'required' => true],
            ['key' => 'url',                    'label' => 'CDN / Custom URL',       'type' => 'text',     'required' => false],
            ['key' => 'directory_prefix',       'label' => 'Directory Prefix',       'type' => 'text',     'required' => false],
        ],

        'storage_wasabi' => [
            ['key' => 'key',                    'label' => 'Access Key ID',          'type' => 'text',     'required' => true],
            ['key' => 'secret',                 'label' => 'Secret Access Key',      'type' => 'password', 'required' => true],
            ['key' => 'region',                 'label' => 'Region (e.g. us-east-1)', 'type' => 'text',     'required' => true],
            ['key' => 'bucket',                 'label' => 'Bucket Name',            'type' => 'text',     'required' => true],
            ['key' => 'endpoint',               'label' => 'Endpoint URL',           'type' => 'text',     'required' => true],
            ['key' => 'url',                    'label' => 'Custom URL (optional)',   'type' => 'text',     'required' => false],
            ['key' => 'directory_prefix',       'label' => 'Directory Prefix',       'type' => 'text',     'required' => false],
        ],
    ];

    protected $fillable = [
        'provider',
        'label',
        'mode',
        'enabled',
        'is_default',
        'credentials',
        'webhook_secret',
        'meta_json',
        'updated_by_admin_id',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected $hidden = ['credentials', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_default' => 'boolean',
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'meta_json' => 'array',
            'last_tested_at' => 'datetime',
        ];
    }

    public static function forProvider(string $provider, string $mode = 'live'): ?self
    {
        return static::where('provider', $provider)->where('mode', $mode)->first();
    }

    public function isConfigured(): bool
    {
        // Local storage needs no credentials — it is always considered configured
        if ($this->provider === 'storage_local') {
            return true;
        }

        $creds = $this->credentials ?? [];

        return ! empty($creds) && collect($creds)->filter()->isNotEmpty();
    }

    /** Returns a fixed-length masked preview — never reveals actual credential content. */
    public function maskedCredentials(): array
    {
        $creds = $this->credentials ?? [];
        $result = [];
        foreach ($creds as $k => $v) {
            $result[$k] = (string) $v === '' ? '' : '••••••••••••';
        }

        return $result;
    }
}
