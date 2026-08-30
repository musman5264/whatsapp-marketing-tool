<?php

namespace App\Models;

use App\Providers\BrandingServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SystemSetting extends Model
{
    protected static function booted(): void
    {
        // Branding is cached at boot (see BrandingServiceProvider); any write to a
        // setting must invalidate it, otherwise a renamed brand keeps serving the
        // old name until the cache is manually cleared.
        $flush = fn () => Cache::forget(BrandingServiceProvider::CACHE_KEY);
        static::saved($flush);
        static::deleted($flush);
    }

    protected $fillable = ['key', 'value', 'is_secret', 'group'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }

    public function getValueAttribute($value): ?string
    {
        $raw = $this->attributes['value'] ?? $value;
        if ($this->is_secret && $raw) {
            try {
                return Crypt::decryptString($raw);
            } catch (\Throwable) {
                return null;
            }
        }

        return $raw;
    }

    public function setValueAttribute($value): void
    {
        if ($this->is_secret && $value !== null && $value !== '') {
            $this->attributes['value'] = Crypt::encryptString($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    public static function get(string $key, $default = null)
    {
        $s = static::where('key', $key)->first();

        return $s ? $s->value : $default;
    }

    public static function set(string $key, $value, bool $isSecret = false, ?string $group = null): void
    {
        $s = static::firstOrNew(['key' => $key]);
        $s->value = $value;
        $s->is_secret = $isSecret;
        $s->group = $group;
        $s->save();
    }
}
