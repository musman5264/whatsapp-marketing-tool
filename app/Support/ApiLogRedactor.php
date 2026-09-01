<?php

namespace App\Support;

/**
 * Pure helper: strips secrets out of API request/response data before it is
 * written to api_request_logs, and bounds body size. No framework deps beyond
 * config().
 */
class ApiLogRedactor
{
    private const MASK = '[redacted]';

    private const MAX_DEPTH = 12;

    /**
     * Recursively replace values of sensitive keys with [redacted].
     * Keys are matched case-insensitively against config('api.logging.redact_keys').
     */
    public static function redactArray(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['[truncated: max depth]'];
        }

        $keys = array_map('strtolower', (array) config('api.logging.redact_keys', []));
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $keys, true)) {
                $out[$key] = self::MASK;

                continue;
            }

            $out[$key] = is_array($value)
                ? self::redactArray($value, $depth + 1)
                : $value;
        }

        return $out;
    }

    /**
     * Mask Authorization / Cookie / CSRF headers. $headers is Symfony's
     * headers->all() shape: ['name' => ['value', ...]].
     */
    public static function redactHeaders(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $values) {
            $lower = strtolower($name);

            if ($lower === 'authorization') {
                $first = $values[0] ?? '';
                $out[$name] = [
                    str_starts_with(strtolower($first), 'bearer ')
                        ? 'Bearer ****'.substr($first, -4)
                        : self::MASK,
                ];

                continue;
            }

            if (in_array($lower, ['cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token'], true)) {
                $out[$name] = [self::MASK];

                continue;
            }

            $out[$name] = $values;
        }

        return $out;
    }

    /**
     * Prepare a body string for storage. Returns null for empty input, else
     * ['body' => string, 'truncated' => bool, 'full_size' => int].
     */
    public static function bodyForStorage(?string $raw, ?string $contentType): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $fullSize = strlen($raw);
        $max = (int) config('api.logging.max_body_bytes', 16384);
        $ct = strtolower((string) $contentType);

        if (str_contains($ct, 'multipart/form-data')) {
            return ['body' => "[multipart upload, {$fullSize} bytes]", 'truncated' => false, 'full_size' => $fullSize];
        }

        $isJson = str_contains($ct, 'json') || (str_starts_with(trim($raw), '{') || str_starts_with(trim($raw), '['));
        $decoded = $isJson ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            return ['body' => "[non-JSON body, {$fullSize} bytes]", 'truncated' => false, 'full_size' => $fullSize];
        }

        $clean = json_encode(self::redactArray($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (strlen($clean) > $max) {
            return ['body' => substr($clean, 0, $max), 'truncated' => true, 'full_size' => $fullSize];
        }

        return ['body' => $clean, 'truncated' => false, 'full_size' => $fullSize];
    }
}
