<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Services\StorageManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http as HttpFacade;
use Illuminate\Support\Facades\Storage;

class ConnectionTester
{
    public function test(IntegrationConfig $config): array
    {
        try {
            $result = match (true) {
                $config->provider === 'meta_app' => $this->testMeta($config),
                str_starts_with($config->provider, 'oauth_') => $this->testOAuth($config),
                str_starts_with($config->provider, 'llm_') => $this->testLlm($config),
                str_starts_with($config->provider, 'sms_') => $this->testSms($config),
                $config->provider === 'google_places' => $this->testGooglePlaces($config),
                $config->provider === 'google_workspace' => $this->testGoogleWorkspace($config),
                $config->provider === 'qdrant' => $this->testQdrant($config),
                $config->provider === 'whatsapp_web' => $this->testWhatsappWeb($config),
                str_starts_with($config->provider, 'storage_') => $this->testStorage($config),
                default => ['ok' => false, 'message' => 'No test available for this provider.'],
            };
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        $config->update([
            'last_tested_at' => now(),
            'last_test_status' => $result['ok'] ? 'ok' : 'fail',
            'last_test_message' => $result['message'] ?? null,
        ]);

        return $result;
    }

    private function testMeta(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        $token = $creds['system_user_token'] ?? '';
        if (empty($token)) {
            return ['ok' => false, 'message' => 'System user token is not configured.'];
        }
        $resp = HttpFacade::timeout(10)->get('https://graph.facebook.com/v20.0/me', ['access_token' => $token]);
        if ($resp->successful() && isset($resp->json()['id'])) {
            return ['ok' => true, 'message' => 'Connected. User ID: '.$resp->json()['id']];
        }

        return ['ok' => false, 'message' => $resp->json()['error']['message'] ?? 'Meta API error.'];
    }

    private function testOAuth(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        $id = $creds['client_id'] ?? $creds['client_key'] ?? '';
        $secret = $creds['client_secret'] ?? '';
        if (empty($id) || empty($secret)) {
            return ['ok' => false, 'message' => 'Client ID and Secret are required.'];
        }

        return ['ok' => true, 'message' => 'Credentials are present. OAuth flow will validate them at runtime.'];
    }

    private function testLlm(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        $apiKey = $creds['api_key'] ?? '';
        if (empty($apiKey)) {
            return ['ok' => false, 'message' => 'API key is required.'];
        }

        if (str_contains($config->provider, 'openai')) {
            $resp = HttpFacade::timeout(15)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                    'max_tokens' => 1,
                ]);

            return $resp->successful()
                ? ['ok' => true,  'message' => 'OpenAI connection successful.']
                : ['ok' => false, 'message' => $resp->json()['error']['message'] ?? 'OpenAI error.'];
        }

        if (str_contains($config->provider, 'anthropic')) {
            $resp = HttpFacade::timeout(15)
                ->withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-3-haiku-20240307',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                ]);

            return $resp->successful()
                ? ['ok' => true,  'message' => 'Anthropic connection successful.']
                : ['ok' => false, 'message' => $resp->json()['error']['message'] ?? 'Anthropic error.'];
        }

        if (str_contains($config->provider, 'gemini')) {
            $resp = HttpFacade::timeout(15)
                ->get("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}");

            return $resp->successful()
                ? ['ok' => true,  'message' => 'Gemini connection successful.']
                : ['ok' => false, 'message' => $resp->json()['error']['message'] ?? 'Gemini error.'];
        }

        return ['ok' => false, 'message' => 'Unknown LLM provider.'];
    }

    private function testSms(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];

        if (str_contains($config->provider, 'twilio')) {
            $sid = $creds['account_sid'] ?? '';
            $token = $creds['auth_token'] ?? '';
            if (empty($sid) || empty($token)) {
                return ['ok' => false, 'message' => 'Account SID and Auth Token required.'];
            }
            $resp = HttpFacade::timeout(10)->withBasicAuth($sid, $token)
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");

            return $resp->successful()
                ? ['ok' => true,  'message' => 'Twilio account connected: '.$resp->json()['friendly_name']]
                : ['ok' => false, 'message' => $resp->json()['message'] ?? 'Twilio error.'];
        }

        if (str_contains($config->provider, 'nexmo')) {
            $key = $creds['api_key'] ?? '';
            $secret = $creds['api_secret'] ?? '';
            if (empty($key) || empty($secret)) {
                return ['ok' => false, 'message' => 'API Key and Secret required.'];
            }
            $resp = HttpFacade::timeout(10)
                ->get('https://rest.nexmo.com/account/get-balance', ['api_key' => $key, 'api_secret' => $secret]);

            return $resp->successful()
                ? ['ok' => true,  'message' => 'Vonage balance: '.$resp->json()['value'].' EUR']
                : ['ok' => false, 'message' => $resp->json()['error-text'] ?? 'Vonage error.'];
        }

        if (str_contains($config->provider, 'messagebird')) {
            $key = $creds['api_key'] ?? '';
            if (empty($key)) {
                return ['ok' => false, 'message' => 'API Key required.'];
            }
            $resp = HttpFacade::timeout(10)
                ->withToken($key)
                ->get('https://rest.messagebird.com/balance');

            return $resp->successful()
                ? ['ok' => true,  'message' => 'MessageBird balance: '.$resp->json()['amount'].' '.$resp->json()['type']]
                : ['ok' => false, 'message' => 'MessageBird error.'];
        }

        // For SMSBD and REVE: just validate keys are present
        $key = $creds['api_key'] ?? '';

        return empty($key)
            ? ['ok' => false, 'message' => 'API key is required.']
            : ['ok' => true,  'message' => 'Credentials are present. Live test requires sending a message.'];
    }

    private function testGooglePlaces(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        $key = $creds['api_key'] ?? '';
        if (empty($key)) {
            return ['ok' => false, 'message' => 'API Key is required.'];
        }
        $resp = HttpFacade::timeout(10)->get('https://maps.googleapis.com/maps/api/place/textsearch/json', [
            'query' => 'restaurants in New York',
            'key' => $key,
            'maxResultCount' => 1,
        ]);
        $status = $resp->json()['status'] ?? '';

        return in_array($status, ['OK', 'ZERO_RESULTS'])
            ? ['ok' => true,  'message' => 'Google Places API connected.']
            : ['ok' => false, 'message' => $resp->json()['error_message'] ?? 'Places API error: '.$status];
    }

    private function testGoogleWorkspace(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        if (empty($creds['client_id']) || empty($creds['client_secret']) || empty($creds['refresh_token'])) {
            return ['ok' => false, 'message' => 'Client ID, Client Secret and Refresh Token are required.'];
        }

        // Exchange the refresh token for an access token — proves the grant is valid.
        $resp = HttpFacade::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'refresh_token' => $creds['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        return $resp->successful() && $resp->json('access_token')
            ? ['ok' => true,  'message' => 'Google Workspace connection successful.']
            : ['ok' => false, 'message' => $resp->json('error_description') ?? $resp->json('error') ?? 'Google token exchange failed.'];
    }

    private function testQdrant(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        $url = rtrim($creds['url'] ?? '', '/');
        $key = $creds['api_key'] ?? '';
        if (empty($url)) {
            return ['ok' => false, 'message' => 'Qdrant URL is required.'];
        }
        $headers = $key ? ['api-key' => $key] : [];
        $resp = HttpFacade::timeout(10)->withHeaders($headers)->get($url.'/collections');

        return $resp->successful()
            ? ['ok' => true,  'message' => 'Qdrant connected. Collections: '.count($resp->json()['result']['collections'] ?? [])]
            : ['ok' => false, 'message' => 'Qdrant error: '.$resp->status()];
    }

    private function testWhatsappWeb(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        $url = rtrim($creds['base_url'] ?? '', '/');
        $key = $creds['api_key'] ?? '';
        if (empty($url)) {
            return ['ok' => false, 'message' => 'WAHA URL is required.'];
        }

        $headers = $key ? ['X-Api-Key' => $key] : [];
        $resp = HttpFacade::timeout(10)->withHeaders($headers)->get($url.'/api/server/status');

        if ($resp->successful()) {
            return ['ok' => true, 'message' => 'WAHA reachable.'];
        }

        return $resp->status() === 401
            ? ['ok' => false, 'message' => 'WAHA rejected the API key (401). Check the key matches WAHA_API_KEY on the container.']
            : ['ok' => false, 'message' => 'WAHA error: HTTP '.$resp->status().'. Check the URL is reachable from this server.'];
    }

    private function testStorage(IntegrationConfig $config): array
    {
        if ($config->provider === 'storage_local') {
            $disk = Storage::disk('public');
            $testPath = '.storage-test/ping.txt';
            $disk->put($testPath, 'ok');
            $exists = $disk->exists($testPath);
            $disk->delete($testPath);

            return $exists
                ? ['ok' => true,  'message' => 'Local (public) disk is writable.']
                : ['ok' => false, 'message' => 'Could not write to local public disk.'];
        }

        $creds = $config->credentials ?? [];
        $key = $creds['key'] ?? '';
        $secret = $creds['secret'] ?? '';
        $bucket = $creds['bucket'] ?? '';

        if (empty($key) || empty($secret) || empty($bucket)) {
            return ['ok' => false, 'message' => 'Access key, secret, and bucket are required.'];
        }

        // Temporarily wire credentials into the disk config
        $manager = app(StorageManager::class);
        $manager->clearCache();

        $diskName = IntegrationConfig::STORAGE_DISK_MAP[$config->provider] ?? null;
        if (! $diskName) {
            return ['ok' => false, 'message' => 'Unknown storage provider.'];
        }

        $diskCfg = [
            'driver' => 's3',
            'key' => $key,
            'secret' => $secret,
            'region' => $creds['region'] ?? 'us-east-1',
            'bucket' => $bucket,
            'url' => $creds['url'] ?? null,
            'endpoint' => $creds['endpoint'] ?? null,
            'use_path_style_endpoint' => false,
            'throw' => true,
            'visibility' => 'private',
            'options' => [],
        ];

        Config::set("filesystems.disks.{$diskName}", $diskCfg);
        Storage::forgetDisk($diskName);

        try {
            $testPath = '.storage-test/ping.txt';
            $disk = Storage::disk($diskName);
            $disk->put($testPath, 'ok');
            $exists = $disk->exists($testPath);
            $disk->delete($testPath);

            $prefix = trim($creds['directory_prefix'] ?? '', '/');

            return $exists
                ? ['ok' => true,  'message' => 'Connected to bucket "'.$bucket.'"'.($prefix ? " (prefix: {$prefix})" : '').'.']
                : ['ok' => false, 'message' => 'Write test failed — check bucket permissions.'];
        } catch (\Throwable $e) {
            $cause = $e->getPrevious();
            $detail = $cause ? ' ('.$cause->getMessage().')' : '';

            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage().$detail];
        }
    }
}
