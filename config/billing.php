<?php

return [
    'gateways' => [
        'stripe' => [
            'enabled' => env('BILLING_STRIPE_ENABLED', false),
            'secret_key' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'success_url' => env('STRIPE_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('STRIPE_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
        'paypal' => [
            'enabled' => env('BILLING_PAYPAL_ENABLED', false),
            'client_id' => env('PAYPAL_CLIENT_ID'),
            'client_secret' => env('PAYPAL_CLIENT_SECRET'),
            'sandbox' => env('PAYPAL_SANDBOX', true),
            'success_url' => env('PAYPAL_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('PAYPAL_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
            'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        ],
        'paddle' => [
            'enabled' => env('BILLING_PADDLE_ENABLED', false),
            'api_key' => env('PADDLE_API_KEY'),
            'environment' => env('PADDLE_ENVIRONMENT', 'sandbox'),
            'success_url' => env('PADDLE_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('PADDLE_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
            'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
        ],
        // Razorpay (India) — native Subscriptions API, webhook-driven renewals.
        'razorpay' => [
            'enabled' => env('BILLING_RAZORPAY_ENABLED', false),
            'key_id' => env('RAZORPAY_KEY_ID'),
            'key_secret' => env('RAZORPAY_KEY_SECRET'),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        ],
        // Cashfree (India) — native Subscriptions API (JS-SDK authorization), webhook-driven.
        'cashfree' => [
            'enabled' => env('BILLING_CASHFREE_ENABLED', false),
            'client_id' => env('CASHFREE_CLIENT_ID'),
            'client_secret' => env('CASHFREE_CLIENT_SECRET'),
            'sandbox' => env('CASHFREE_SANDBOX', true),
            'return_url' => env('CASHFREE_RETURN_URL', env('APP_URL') . '/app/billing?checkout=success'),
        ],
        // Tap (MENA/GCC) — hosted first charge + merchant-initiated saved-card renewals.
        'tap' => [
            'enabled' => env('BILLING_TAP_ENABLED', false),
            'secret_key' => env('TAP_SECRET_KEY'),
            'success_url' => env('TAP_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('TAP_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
        // Paystack (Africa: Nigeria, Ghana, South Africa, Kenya, Rwanda, etc.)
        // Native Subscriptions API — webhook-driven renewals.
        'paystack' => [
            'enabled' => env('BILLING_PAYSTACK_ENABLED', false),
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'success_url' => env('PAYSTACK_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('PAYSTACK_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
        // Xendit (Southeast Asia: Indonesia, Philippines, Vietnam, Thailand, Malaysia)
        // Native Recurring Plans API — webhook-driven renewals.
        'xendit' => [
            'enabled' => env('BILLING_XENDIT_ENABLED', false),
            'secret_key' => env('XENDIT_SECRET_KEY'),
            'webhook_token' => env('XENDIT_WEBHOOK_TOKEN'),
            'success_url' => env('XENDIT_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('XENDIT_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
        // Paymob (Egypt, Jordan, Pakistan, Morocco, Saudi Arabia, UAE)
        // MIT save-card pattern — merchant-initiated recurring via scheduler.
        'paymob' => [
            'enabled' => env('BILLING_PAYMOB_ENABLED', false),
            'api_key' => env('PAYMOB_API_KEY'),
            'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
            'integration_id' => (int) env('PAYMOB_INTEGRATION_ID', 0),
            'iframe_id' => env('PAYMOB_IFRAME_ID'),
            'success_url' => env('PAYMOB_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('PAYMOB_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
        // MyFatoorah (Kuwait, Saudi Arabia, UAE, Bahrain, Oman, Qatar, Jordan)
        // MIT save-token pattern — merchant-initiated recurring via scheduler.
        'myfatoorah' => [
            'enabled' => env('BILLING_MYFATOORAH_ENABLED', false),
            'api_key' => env('MYFATOORAH_API_KEY'),
            'sandbox' => env('MYFATOORAH_SANDBOX', true),
            'success_url' => env('MYFATOORAH_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('MYFATOORAH_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
        // Mollie (Europe: Netherlands, Belgium, Germany, France, and more)
        // Native Customers + Subscriptions API — webhook-driven renewals.
        'mollie' => [
            'enabled' => env('BILLING_MOLLIE_ENABLED', false),
            'api_key' => env('MOLLIE_API_KEY'),
            'success_url' => env('MOLLIE_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('MOLLIE_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
        // Square (US, Canada, UK, Australia, Ireland, France, Spain, Japan)
        // Native Catalog + Subscriptions API (invoice-billed) — webhook-driven renewals.
        'square' => [
            'enabled' => env('BILLING_SQUARE_ENABLED', false),
            'access_token' => env('SQUARE_ACCESS_TOKEN'),
            'location_id' => env('SQUARE_LOCATION_ID'),
            'signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
            'sandbox' => env('SQUARE_SANDBOX', true),
            'success_url' => env('SQUARE_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('SQUARE_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
        // iyzico (Turkey) — native Subscription API, webhook-driven renewals.
        // Subscriptions are limited to TRY, USD and EUR. The checkout form is embedded
        // HTML (no hosted redirect), so we render it ourselves at /billing/iyzico/form.
        'iyzico' => [
            'enabled' => env('BILLING_IYZICO_ENABLED', false),
            'api_key' => env('IYZICO_API_KEY'),
            'secret_key' => env('IYZICO_SECRET_KEY'),
            // Needed only to verify the X-IYZ-SIGNATURE-V3 webhook header.
            'merchant_id' => env('IYZICO_MERCHANT_ID'),
            'sandbox' => env('IYZICO_SANDBOX', true),
            // iyzico requires a full Turkish-format customer profile on every checkout.
            // The app stores no phone or national ID, so these stand in. A live TR account
            // will reject placeholder values — set real ones before going live.
            'customer_defaults' => [
                'gsm_number' => env('IYZICO_DEFAULT_GSM', '+905350000000'),
                'identity_number' => env('IYZICO_DEFAULT_IDENTITY', '11111111111'),
                'address' => env('IYZICO_DEFAULT_ADDRESS', 'Not collected'),
                'city' => env('IYZICO_DEFAULT_CITY', 'Istanbul'),
                'country' => env('IYZICO_DEFAULT_COUNTRY', 'Turkey'),
                'zip_code' => env('IYZICO_DEFAULT_ZIP'),
            ],
            'success_url' => env('IYZICO_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('IYZICO_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
        // Mercado Pago (Latin America: Brazil, Argentina, Mexico, Chile, Colombia, Peru, Uruguay)
        // Native Preapproval (subscriptions) API — webhook-driven renewals.
        'mercadopago' => [
            'enabled' => env('BILLING_MERCADOPAGO_ENABLED', false),
            'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
            'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
            'success_url' => env('MERCADOPAGO_SUCCESS_URL', env('APP_URL') . '/app/billing?checkout=success'),
            'cancel_url' => env('MERCADOPAGO_CANCEL_URL', env('APP_URL') . '/app/pricing?checkout=canceled'),
        ],
    ],
];
