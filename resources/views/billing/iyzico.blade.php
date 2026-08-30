<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Secure checkout') }} · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            padding: 2.5rem 1rem;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f5f5f5;
            color: #171717;
        }
        .wrap { max-width: 48rem; margin: 0 auto; }
        h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 1.5rem; }
        .card {
            background: #fff;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgb(0 0 0 / 0.1);
        }
        .back { display: inline-block; margin-top: 1.5rem; font-size: 0.875rem; color: #525252; }
        @media (prefers-color-scheme: dark) {
            body { background: #171717; color: #f5f5f5; }
            .card { background: #262626; box-shadow: none; }
            .back { color: #a3a3a3; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>{{ __('Secure checkout') }}</h1>
        <div class="card">
            {{-- Form markup returned by the iyzico Subscription API. It carries its own
                 scripts, so it is rendered unescaped and must never be user-supplied. --}}
            {!! $checkoutFormContent !!}
        </div>
        <a class="back" href="{{ $cancelUrl }}">&larr; {{ __('Back to pricing') }}</a>
    </div>
</body>
</html>
