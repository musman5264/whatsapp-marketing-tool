<?php

namespace App\Modules\Integrations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One call to a linked system (or one manifest read), for the admin's "recent calls" table.
 * Never holds a key or a full phone number. `request` and `response` are only filled while an
 * administrator has switched debug recording on, they are masked and cut to 2 KB, and they are
 * wiped after a day.
 *
 * @property int $id
 * @property int $integration_id
 * @property string $capability
 * @property string $outcome found | not_found | no_data
 * @property string $status HTTP status, or the reason when nothing came back
 * @property int $ms
 * @property string|null $message
 * @property string|null $request
 * @property string|null $response
 * @property Carbon|null $created_at
 */
class ExternalCallLog extends Model
{
    public const UPDATED_AT = null;

    public const PAYLOAD_BYTES = 2048;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public static function record(ExternalIntegration $integration, string $capability, string $outcome, string $status, int $ms, string $message, ?string $request = null, ?string $response = null): void
    {
        self::create([
            'integration_id' => $integration->id,
            'capability' => $capability,
            'outcome' => $outcome,
            'status' => mb_substr($status, 0, 32),
            'ms' => $ms,
            'message' => mb_substr($message, 0, 255),
            'request' => $request,
            'response' => $response,
        ]);

        if (random_int(1, 50) === 1) {
            self::prune();
        }
    }

    /** Rows older than 30 days are deleted; request/response older than a day are wiped. */
    public static function prune(): void
    {
        self::where('created_at', '<', now()->subDays(30))->delete();
        self::where('created_at', '<', now()->subDay())
            ->where(fn ($q) => $q->whereNotNull('request')->orWhereNotNull('response'))
            ->update(['request' => null, 'response' => null]);
    }

    /** Masked, then cut: masking first so a cut can never leave half a number visible. */
    public static function payload(string $text): string
    {
        return mb_substr(self::mask($text), 0, self::PAYLOAD_BYTES);
    }

    /** +923345266444 (also url-encoded, or local 03345266444) becomes +9233•••6444. */
    public static function mask(string $text): string
    {
        $text = preg_replace_callback('/(\+|%2B)(\d{8,15})/i', fn ($m) => $m[1].substr($m[2], 0, 4).'•••'.substr($m[2], -4), $text);

        return preg_replace_callback('/(?<![\d+])(0\d{9,11})(?!\d)/', fn ($m) => substr($m[1], 0, 4).'•••'.substr($m[1], -4), $text);
    }
}
