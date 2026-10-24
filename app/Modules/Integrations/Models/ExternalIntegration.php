<?php

namespace App\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $type
 * @property string $name
 * @property string $base_url
 * @property string $manifest_url
 * @property string $key
 * @property string|null $manifest_version
 * @property array<string, mixed>|null $manifest
 * @property list<array{key: string, reason: string}>|null $manifest_issues
 * @property Carbon|null $manifest_fetched_at
 * @property string $status
 * @property int $consecutive_failures
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $last_ping_at
 * @property Carbon|null $debug_until
 *
 * A remote system (EPOS today) linked to a workspace. Holds its API key (encrypted)
 * and the cached manifest of questions it publishes. Customer data is never stored here.
 */
class ExternalIntegration extends Model
{
    public const TYPES = ['epos'];

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_UNREACHABLE = 'unreachable';

    protected $fillable = ['workspace_id', 'type', 'name', 'base_url', 'manifest_url', 'key'];

    protected $hidden = ['key'];

    protected function casts(): array
    {
        return [
            'key' => 'encrypted',
            'manifest' => 'array',
            'manifest_issues' => 'array',
            'manifest_fetched_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'last_ping_at' => 'datetime',
            'debug_until' => 'datetime',
        ];
    }

    /** True while an administrator has request/response recording switched on (it switches itself off). */
    public function isDebugging(): bool
    {
        return (bool) $this->debug_until?->isFuture();
    }

    public function capabilityCount(): int
    {
        return count($this->manifest['capabilities'] ?? []);
    }
}
