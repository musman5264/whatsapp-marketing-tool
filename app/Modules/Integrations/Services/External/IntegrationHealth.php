<?php

namespace App\Modules\Integrations\Services\External;

use App\Modules\Integrations\Models\ExternalIntegration;

/**
 * Tracks whether a linked system answers. 3 failures in a row mark it unreachable; retries back off
 * 1 -> 5 -> 30 minutes; the first success restores it.
 */
class IntegrationHealth
{
    private const BACKOFF_SECONDS = [60, 300, 1800];

    public function __construct(private readonly SafeHttp $http) {}

    public function recordSuccess(ExternalIntegration $integration): void
    {
        $integration->forceFill([
            'status' => ExternalIntegration::STATUS_CONNECTED,
            'consecutive_failures' => 0,
            'next_retry_at' => null,
        ])->save();
    }

    public function recordFailure(ExternalIntegration $integration): void
    {
        $failures = $integration->consecutive_failures + 1;
        $integration->consecutive_failures = $failures;

        if ($failures >= 3) {
            $step = min($failures - 3, count(self::BACKOFF_SECONDS) - 1);
            $integration->status = ExternalIntegration::STATUS_UNREACHABLE;
            $integration->next_retry_at = now()->addSeconds(self::BACKOFF_SECONDS[$step]);
        }

        $integration->save();
    }

    /** Cheap GET {base_url}/api/whatsapp/v1/ping. Not more than once a minute unless forced. */
    public function ping(ExternalIntegration $integration, bool $force = false): bool
    {
        if (! $force && $integration->last_ping_at?->gt(now()->subMinute())) {
            return $integration->status === ExternalIntegration::STATUS_CONNECTED;
        }

        try {
            $ok = $this->http->request('GET', $integration->base_url.'/api/whatsapp/v1/ping', $integration->key, null, 4096)->successful();
        } catch (ExternalCallException) {
            $ok = false;
        }

        $integration->last_ping_at = now();
        $ok ? $this->recordSuccess($integration) : $this->recordFailure($integration);

        return $ok;
    }
}
