<?php

namespace App\Support;

/**
 * Brand-name token used inside stored, admin-editable content.
 *
 * Marketing copy and CMS pages are seeded into the database, so interpolating the
 * brand name at seed time would freeze whatever it was at install and leave the
 * content stale after a rename. Storing `:brand` and resolving it on read keeps
 * every white-label install correct, however often the brand changes.
 */
class Brand
{
    public const TOKEN = ':brand';

    /** The brand name an admin configured (falls back to .env APP_NAME). */
    public static function name(): string
    {
        return (string) config('app.name');
    }

    /** Resolve the token in a single value. Safe to call on null. */
    public static function apply(?string $value): string
    {
        return str_replace(self::TOKEN, self::name(), (string) $value);
    }

    /**
     * Resolve the token across an array of values, leaving non-strings untouched.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function applyMany(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = self::apply($value);
            }
        }

        return $values;
    }
}
