<?php

namespace App\Services\License;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integration with the Botble License Manager external API
 * (https://docs.botble.com/license-manager/).
 *
 * Server URL, API key and product id are baked into config/license.php by the
 * author and hidden from the buyer. The activated license token is stored in a
 * private file (storage/app/.license); verification results are cached so the
 * server is not hit on every request, and a short grace window keeps a
 * legitimately-licensed app running if the license server is briefly down.
 */
class LicenseManager
{
    private const CACHE_KEY = 'license.verified';

    /** Licensing is active only when fully configured and not switched off. */
    public function enabled(): bool
    {
        return (bool) config('license.verify')
            && filled(config('license.product_id'))
            && filled(config('license.api_key'))
            && filled(config('license.server_url'));
    }

    /** Verification modes the License Manager supports. */
    public const TYPES = ['envato', 'non_envato', 'gumroad'];

    /** The configured default verification mode. */
    public function verifyType(): string
    {
        $type = (string) config('license.verify_type', 'non_envato');

        return in_array($type, self::TYPES, true) ? $type : 'non_envato';
    }

    /**
     * Code types offered to the buyer in the activation UI.
     *
     * @return list<string>
     */
    public function verifyTypes(): array
    {
        $types = array_values(array_intersect((array) config('license.verify_types', []), self::TYPES));

        return $types !== [] ? $types : [$this->verifyType()];
    }

    /** Default selected type — the configured one if it's offered, else the first offered. */
    public function defaultVerifyType(): string
    {
        $types = $this->verifyTypes();

        return in_array($this->verifyType(), $types, true) ? $this->verifyType() : ($types[0] ?? 'non_envato');
    }

    public function isEnvato(): bool
    {
        return $this->verifyType() === 'envato';
    }

    /** The verify type actually used at activation (for display); falls back to the default. */
    public function activatedType(): string
    {
        $path = $this->typePath();
        if (is_file($path)) {
            $type = trim((string) @file_get_contents($path));
            if (in_array($type, self::TYPES, true)) {
                return $type;
            }
        }

        return $this->defaultVerifyType();
    }

    public function storagePath(): string
    {
        return storage_path('app/.license');
    }

    public function isActivated(): bool
    {
        return $this->licenseData() !== null;
    }

    public function licenseData(): ?string
    {
        $path = $this->storagePath();
        if (! is_file($path)) {
            return null;
        }
        $data = trim((string) @file_get_contents($path));

        return $data !== '' ? $data : null;
    }

    /**
     * The activated purchase/license code with the middle masked, for display.
     * Returns null if the raw code wasn't captured (e.g. activated before this
     * was added). Length is hidden by using a fixed-width mask.
     */
    public function maskedCode(): ?string
    {
        $code = $this->rawCode();
        if ($code === null) {
            return null;
        }

        $len = strlen($code);
        if ($len <= 8) {
            return str_repeat('•', max(1, $len - 2)).substr($code, -2);
        }

        return substr($code, 0, 4).str_repeat('•', 8).substr($code, -4);
    }

    // ── API calls ───────────────────────────────────────────────────────────

    /** @return array{ok: bool, message: string} */
    public function connectionCheck(): array
    {
        try {
            $res = $this->http()->get($this->url('/api/external/connection-check'));
            $ok = $res->successful() && (bool) ($res->json('is_active') ?? $res->json('status') ?? false);

            return ['ok' => $ok, 'message' => (string) ($res->json('message') ?? ($ok ? 'Connection successful.' : 'Connection failed.'))];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "Couldn't reach the license server."];
        }
    }

    /** @return array{ok: bool, message: string} */
    public function activate(string $licenseCode, string $clientName = '', ?string $verifyType = null): array
    {
        $licenseCode = trim($licenseCode);

        if ($licenseCode === '') {
            return ['ok' => false, 'message' => 'Enter your license code.'];
        }
        if (! $this->enabled()) {
            return ['ok' => true, 'message' => 'License verification is disabled.'];
        }

        $type = in_array($verifyType, self::TYPES, true) ? $verifyType : $this->verifyType();

        try {
            $res = $this->http()->post($this->url('/api/external/license/activate'), [
                'product_id' => (string) config('license.product_id'),
                'license_code' => $licenseCode,
                'client_name' => $clientName,
                'verify_type' => $type,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => "Couldn't reach the license server. Check the server's internet connection and try again."];
        }

        if (! ($res->successful() && (bool) ($res->json('is_active') ?? false))) {
            return ['ok' => false, 'message' => (string) ($res->json('message') ?? 'License activation failed.')];
        }

        $licenseData = $res->json('lic_response') ?? $res->json('data.license_data');
        if (! is_string($licenseData) || trim($licenseData) === '') {
            return ['ok' => false, 'message' => 'The license server did not return license data. Please try again.'];
        }

        $this->storeLicenseData($licenseData);
        $this->storeCode($licenseCode);
        $this->storeType($type);
        $this->cacheValid();

        return ['ok' => true, 'message' => (string) ($res->json('message') ?? 'License activated.')];
    }

    /**
     * @return array{ok: bool, message: string, needs_activation?: bool}
     */
    public function verify(bool $useCache = true): array
    {
        if (! $this->enabled()) {
            return ['ok' => true, 'message' => 'License verification is disabled.'];
        }

        $licenseData = $this->licenseData();
        if ($licenseData === null) {
            return ['ok' => false, 'message' => 'No license found. Please activate your license.', 'needs_activation' => true];
        }

        if ($useCache && Cache::get(self::CACHE_KEY) === true) {
            return ['ok' => true, 'message' => 'License is valid.'];
        }

        try {
            $res = $this->http()->post($this->url('/api/external/license/verify'), [
                'product_id' => (string) config('license.product_id'),
                'license_data' => $licenseData,
            ]);
        } catch (\Throwable $e) {
            // Fail OPEN with a short grace so a license-server outage doesn't
            // take down a legitimately licensed application.
            Log::warning('License verify: server unreachable, granting grace window. '.$e->getMessage());
            Cache::put(self::CACHE_KEY, true, now()->addHours(min(6, $this->cacheHours())));

            return ['ok' => true, 'message' => 'License server unreachable; using cached grace.'];
        }

        if ($res->successful() && (bool) ($res->json('is_active') ?? false)) {
            $this->cacheValid();

            return ['ok' => true, 'message' => (string) ($res->json('message') ?? 'License is valid.')];
        }

        // Definitive "invalid" from the server — drop the cache so access is
        // re-checked immediately.
        Cache::forget(self::CACHE_KEY);

        return ['ok' => false, 'message' => (string) ($res->json('message') ?? 'License is invalid.'), 'needs_activation' => true];
    }

    /** @return array{ok: bool, message: string} */
    public function deactivate(): array
    {
        $licenseData = $this->licenseData();

        if (! $this->enabled() || $licenseData === null) {
            $this->clearLicenseData();

            return ['ok' => true, 'message' => 'License cleared.'];
        }

        try {
            $res = $this->http()->post($this->url('/api/external/license/deactivate'), [
                'product_id' => (string) config('license.product_id'),
                'license_data' => $licenseData,
            ]);
            $ok = $res->successful() && (bool) ($res->json('is_active') ?? false);
            // Always clear locally so the operator can re-activate cleanly.
            $this->clearLicenseData();

            return ['ok' => $ok, 'message' => (string) ($res->json('message') ?? ($ok ? 'License deactivated.' : 'Deactivation failed on the server, but the local license was cleared.'))];
        } catch (\Throwable $e) {
            $this->clearLicenseData();

            return ['ok' => false, 'message' => "Couldn't reach the license server, but the local license was cleared."];
        }
    }

    /**
     * @return array{ok: bool, update_available: bool, current_version: string, version: ?string, released_at: ?string, summary: ?string, changelog: ?string, update_id: ?string, has_sql: bool, message: string}
     */
    public function checkUpdate(): array
    {
        $base = [
            'ok' => false,
            'update_available' => false,
            'current_version' => (string) config('license.current_version', '1.0.0'),
            'version' => null,
            'released_at' => null,
            'summary' => null,
            'changelog' => null,
            'update_id' => null,
            'has_sql' => false,
            'message' => '',
        ];

        if (! $this->enabled()) {
            return [...$base, 'message' => 'Updates are not configured.'];
        }

        try {
            $res = $this->http()->post($this->url('/api/external/update/check'), [
                'product_id' => (string) config('license.product_id'),
                'current_version' => $base['current_version'],
            ]);
        } catch (\Throwable $e) {
            return [...$base, 'message' => "Couldn't reach the update server."];
        }

        if (! $res->successful()) {
            return [...$base, 'message' => (string) ($res->json('message') ?? 'Update check failed.')];
        }

        $d = $res->json();

        return [
            'ok' => true,
            'update_available' => (bool) ($d['update_available'] ?? false),
            'current_version' => $base['current_version'],
            'version' => $d['version'] ?? null,
            'released_at' => $d['release_date'] ?? $d['released_at'] ?? null,
            'summary' => $d['summary'] ?? null,
            'changelog' => $d['changelog'] ?? null,
            'update_id' => $d['update_id'] ?? null,
            'has_sql' => (bool) ($d['has_sql'] ?? false),
            'message' => (string) ($d['message'] ?? ''),
        ];
    }

    /**
     * Stream an update package ($type = 'main' | 'sql') to a local file.
     *
     * @return array{ok: bool, message: string, path?: string}
     */
    public function downloadUpdate(string $versionId, string $type, string $destination): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'message' => 'Licensing is not configured.'];
        }

        $licenseData = $this->licenseData();
        if ($licenseData === null) {
            return ['ok' => false, 'message' => 'No active license.'];
        }

        try {
            $res = Http::withHeaders($this->headers())
                ->asJson()
                ->timeout(600)
                ->sink($destination)
                ->post($this->url('/api/external/update/'.rawurlencode($versionId).'/download/'.$type), [
                    'product_id' => (string) config('license.product_id'),
                    'license_data' => $licenseData,
                ]);
        } catch (\Throwable $e) {
            @unlink($destination);

            return ['ok' => false, 'message' => 'Download failed: '.$e->getMessage()];
        }

        if (! $res->successful()) {
            @unlink($destination);

            return ['ok' => false, 'message' => 'Update download failed (HTTP '.$res->status().').'];
        }

        // The endpoint streams a binary file on success but returns JSON on
        // error (e.g. invalid license) — detect that so we don't treat an error
        // body as a package.
        if (str_contains((string) $res->header('Content-Type'), 'json')) {
            $json = json_decode((string) @file_get_contents($destination), true);
            @unlink($destination);

            return ['ok' => false, 'message' => (string) ($json['message'] ?? 'The update download was rejected.')];
        }

        return ['ok' => true, 'message' => 'Downloaded.', 'path' => $destination];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function http(): PendingRequest
    {
        return Http::withHeaders($this->headers())
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'X-API-KEY' => (string) config('license.api_key'),
            'X-API-URL' => (string) config('app.url'),
            'X-API-IP' => $this->serverIp(),
            'X-API-LANGUAGE' => app()->getLocale() ?: 'en',
        ];
    }

    private function serverIp(): string
    {
        $ip = request()?->server('SERVER_ADDR');
        if (! $ip) {
            $host = @gethostname();
            $ip = $host ? @gethostbyname($host) : null;
        }

        return is_string($ip) && $ip !== '' ? $ip : '127.0.0.1';
    }

    private function url(string $path): string
    {
        return rtrim((string) config('license.server_url'), '/').$path;
    }

    private function cacheHours(): int
    {
        return max(1, (int) config('license.cache_hours', 12));
    }

    private function cacheValid(): void
    {
        Cache::put(self::CACHE_KEY, true, now()->addHours($this->cacheHours()));
    }

    private function storeLicenseData(string $data): void
    {
        $path = $this->storagePath();
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, trim($data), LOCK_EX);
    }

    private function clearLicenseData(): void
    {
        @unlink($this->storagePath());
        @unlink($this->codePath());
        @unlink($this->typePath());
        Cache::forget(self::CACHE_KEY);
    }

    private function codePath(): string
    {
        return storage_path('app/.license_code');
    }

    private function typePath(): string
    {
        return storage_path('app/.license_type');
    }

    private function storeType(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            return;
        }
        $path = $this->typePath();
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $type, LOCK_EX);
    }

    private function rawCode(): ?string
    {
        $path = $this->codePath();
        if (! is_file($path)) {
            return null;
        }
        $code = trim((string) @file_get_contents($path));

        return $code !== '' ? $code : null;
    }

    private function storeCode(string $code): void
    {
        $code = trim($code);
        if ($code === '') {
            return;
        }
        $path = $this->codePath();
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $code, LOCK_EX);
    }
}
