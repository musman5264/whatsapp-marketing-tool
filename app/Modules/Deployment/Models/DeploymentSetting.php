<?php

namespace App\Modules\Deployment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Key/value store for deployment config. Secret keys (deploy_key, github_token)
 * are encrypted at rest and decrypted on read; other keys are plaintext.
 *
 * @property string $key
 * @property string|null $value
 */
class DeploymentSetting extends Model
{
    protected $table = 'deployment_settings';

    protected $fillable = ['key', 'value'];

    /** Keys whose stored value is encrypted. */
    public const SECRET_KEYS = ['deploy_key', 'github_token'];

    public const DEFAULTS = [
        'repo_url' => '',
        'branch' => 'main',
        'deploy_url' => '',
        'deploy_key' => '',
        'github_token' => '',
        'auto_migrate' => '1',
        'auto_composer' => '0',
        'maintenance_mode' => '0',
        'app_version' => '1.0.0',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        $row = static::query()->where('key', $key)->first();
        if (! $row) {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }

        return $row->decryptedValue();
    }

    public static function put(string $key, ?string $value): void
    {
        $stored = in_array($key, self::SECRET_KEYS, true) && $value !== null && $value !== ''
            ? Crypt::encryptString($value)
            : $value;

        static::query()->updateOrInsert(['key' => $key], ['value' => $stored, 'updated_at' => now()]);
    }

    /**
     * All settings merged with defaults, decrypted.
     *
     * @return array<string,string|null>
     */
    public static function allValues(): array
    {
        $rows = static::query()->get()->keyBy('key');
        $out = self::DEFAULTS;
        foreach ($rows as $key => $row) {
            $out[$key] = $row->decryptedValue();
        }

        return $out;
    }

    public function decryptedValue(): ?string
    {
        if ($this->value === null || $this->value === '') {
            return $this->value;
        }
        if (! in_array($this->key, self::SECRET_KEYS, true)) {
            return $this->value;
        }
        try {
            return Crypt::decryptString($this->value);
        } catch (\Throwable) {
            return null; // corrupted / key rotated
        }
    }
}
