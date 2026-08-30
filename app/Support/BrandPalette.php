<?php

namespace App\Support;

/**
 * Generates a full 50–950 tint/shade ramp from a single hex colour, so the admin
 * can pick one brand colour and every `bg-brand-*` / `text-brand-*` utility in the
 * app follows. The ramp is emitted as space-separated RGB channels ("70 114 53")
 * because Tailwind's colour tokens are defined as
 * `rgb(var(--brand-500) / <alpha-value>)` — the bare-channel form is what keeps
 * opacity modifiers (`bg-brand-500/20`) working.
 *
 * Method: the picked colour is pinned to the 600 stop (the shade the UI uses for
 * primary buttons/links), and the other stops keep its hue and saturation while
 * moving lightness along a fixed curve. The curve's mix ratios were measured off
 * the hand-tuned default forest-green ramp, so a generated ramp keeps the same
 * light-to-dark rhythm as the original design.
 */
class BrandPalette
{
    /**
     * Lightness mix ratios per stop, relative to the 600 anchor.
     * Positive = mix toward white, negative = mix toward black.
     */
    private const CURVE = [
        '50' => 0.924,
        '100' => 0.811,
        '200' => 0.639,
        '300' => 0.429,
        '400' => 0.230,
        '500' => 0.082,
        '600' => 0.0,
        '700' => -0.214,
        '800' => -0.333,
        '900' => -0.407,
        '950' => -0.676,
    ];

    /**
     * The page/sidebar background tints. Lighter than the 50 stop — they read as
     * off-white rather than as a colour, but still carry the brand hue.
     */
    private const SURFACE_CURVE = [
        '' => 0.951,
        '-subtle' => 0.866,
    ];

    /**
     * Build the ramp for a hex colour.
     *
     * @param  array<string, float>  $curve
     * @return array<string, string> stop => "R G B"
     */
    public static function ramp(string $hex, array $curve = self::CURVE): array
    {
        [$h, $s, $l] = self::hexToHsl($hex);

        $ramp = [];
        foreach ($curve as $stop => $ratio) {
            $stopL = $ratio >= 0
                ? $l + $ratio * (1 - $l)   // toward white
                : $l * (1 + $ratio);       // toward black

            $ramp[$stop] = self::hslToChannels($h, $s, $stopL);
        }

        return $ramp;
    }

    /**
     * CSS custom-property declarations for a ramp, e.g. `--brand-50: 242 248 236;`.
     */
    public static function cssVars(string $prefix, string $hex): string
    {
        $out = [];
        foreach (self::ramp($hex) as $stop => $channels) {
            $out[] = "--{$prefix}-{$stop}: {$channels};";
        }

        return implode(' ', $out);
    }

    /** `--surface` / `--surface-subtle` declarations tinted by the brand hue. */
    public static function surfaceVars(string $hex): string
    {
        $out = [];
        foreach (self::ramp($hex, self::SURFACE_CURVE) as $stop => $channels) {
            $out[] = "--surface{$stop}: {$channels};";
        }

        return implode(' ', $out);
    }

    /** A hex string is usable only if it is exactly `#rrggbb`. */
    public static function isValidHex(?string $hex): bool
    {
        return is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1;
    }

    /** @return array{float, float, float} hue 0–1, saturation 0–1, lightness 0–1 */
    private static function hexToHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d == 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match ($max) {
            $r => (($g - $b) / $d) + ($g < $b ? 6 : 0),
            $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        };

        return [$h / 6, $s, $l];
    }

    private static function hslToChannels(float $h, float $s, float $l): string
    {
        if ($s == 0.0) {
            $v = (int) round($l * 255);

            return "{$v} {$v} {$v}";
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        $r = (int) round(self::hueToRgb($p, $q, $h + 1 / 3) * 255);
        $g = (int) round(self::hueToRgb($p, $q, $h) * 255);
        $b = (int) round(self::hueToRgb($p, $q, $h - 1 / 3) * 255);

        return "{$r} {$g} {$b}";
    }

    private static function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    }
}
