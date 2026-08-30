<?php

/*
|--------------------------------------------------------------------------
| License Manager (Botble) — author configuration
|--------------------------------------------------------------------------
|
| The server URL, API key and product id are stored ENCODED below and decoded
| at runtime, so they aren't visible to a casual reader of the source (a grep
| for the key or server domain finds nothing).
|
| NOTE: this is deterrence only. These values are still sent to the license
| server on every request, so they can be recovered from network traffic — the
| real protection is server-side (the API key is verify-scoped, and the server
| enforces activation limits + domain binding). Do not rely on this for secrecy.
|
| To change a value, re-encode it with the same scheme (see the bottom note),
| or override locally via LICENSE_SERVER_URL / LICENSE_API_KEY /
| LICENSE_PRODUCT_ID in your private .env (those win over the baked values).
|
*/

$d = static function (string $b): string {
    // XOR + reverse + base64 (symmetric). Key is assembled from bytes so it is
    // not a greppable string literal.
    $k = "\x57\x6d\x4c\x69\x63\x5f\x32\x30\x32\x36\x5f\x73\x67\x21";
    $x = strrev((string) base64_decode($b, true));
    $o = '';
    for ($i = 0, $n = strlen($x); $i < $n; $i++) {
        $o .= $x[$i] ^ $k[$i % strlen($k)];
    }

    return $o;
};

return [

    // Master kill-switch. Licensing is only active when this is true AND a
    // product id + api key + server URL are configured. Env-overridable so a
    // product update that ships 'true' here can't silently re-enable the gate:
    // set LICENSE_VERIFY in .env (private, not overwritten by updates).
    'verify' => filter_var(env('LICENSE_VERIFY', false), FILTER_VALIDATE_BOOL),

    'server_url' => rtrim((string) (env('LICENSE_SERVER_URL') ?: $d('RlVccQ0MKR8wQBcAcVteHx1lEBk4GT8=')), '/'),
    'api_key' => env('LICENSE_API_KEY') ?: $d('ORlscQB0aSYnGlwBZTBEDHMFamtpUz4HXGc='),
    'product_id' => env('LICENSE_PRODUCT_ID') ?: $d('dQJoIlkOX24='),

    // Default verification type for the activate call: envato | non_envato |
    // gumroad. 'envato' means buyers enter their Envato/CodeCanyon purchase
    // code, which the License Manager validates against Envato for this product.
    'verify_type' => env('LICENSE_VERIFY_TYPE', 'envato'),

    // Code types offered in the installer/activation UI — the buyer picks which
    // kind of code they have. The default selection is 'verify_type' above.
    // Set to a single value to lock the installer to one type (no chooser).
    'verify_types' => ['envato', 'non_envato'],

    // Current product version, reported when checking for updates.
    'current_version' => env('APP_VERSION', '1.0.0'),

    // How long a successful verification is trusted before re-checking the
    // server (the docs recommend 6–24 hours).
    'cache_hours' => (int) env('LICENSE_CACHE_HOURS', 12),

];
