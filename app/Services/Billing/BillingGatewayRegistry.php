<?php

namespace App\Services\Billing;

use App\Contracts\BillingGatewayInterface;
use App\Models\PaymentGatewayConfig;

class BillingGatewayRegistry
{
    /** @var array<string, BillingGatewayInterface> */
    private array $gateways = [];

    public function __construct()
    {
        $this->registerFromDatabase();
        $this->registerFromConfig();
    }

    private function registerFromDatabase(): void
    {
        try {
            $configs = PaymentGatewayConfig::where('enabled', true)->get();
        } catch (\Throwable) {
            return;
        }

        $appUrl = config('app.url', '');
        $successUrl = rtrim($appUrl, '/').'/app/billing?checkout=success';
        $cancelUrl = rtrim($appUrl, '/').'/app/pricing?checkout=canceled';
        $tapWebhookUrl = rtrim($appUrl, '/').'/webhooks/tap';

        foreach ($configs as $row) {
            if (isset($this->gateways[$row->gateway])) {
                continue;
            }
            $creds = $row->getActiveCredentials();
            if ($row->gateway === 'stripe' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['stripe'] = new StripeGateway(
                    $creds['secret_key'],
                    $creds['webhook_secret'] ?? '',
                    $successUrl,
                    $cancelUrl
                );
            }
            if ($row->gateway === 'paypal' && ! empty($creds['client_id'] ?? $creds['secret_key'] ?? '')) {
                $clientId = $creds['client_id'] ?? $creds['publishable_key'] ?? '';
                $clientSecret = $creds['client_secret'] ?? $creds['secret_key'] ?? '';
                if ($clientId !== '' && $clientSecret !== '') {
                    $this->gateways['paypal'] = new PayPalGateway(
                        $clientId,
                        $clientSecret,
                        $row->test_mode,
                        $successUrl,
                        $cancelUrl,
                        $creds['webhook_secret'] ?? $creds['webhook_id'] ?? ''
                    );
                }
            }
            if ($row->gateway === 'paddle' && ! empty($creds['api_key'] ?? $creds['secret_key'] ?? '')) {
                $apiKey = $creds['api_key'] ?? $creds['secret_key'];
                $this->gateways['paddle'] = new PaddleGateway(
                    $apiKey,
                    $row->test_mode ? 'sandbox' : 'production',
                    $successUrl,
                    $cancelUrl,
                    $creds['webhook_secret'] ?? ''
                );
            }
            // Razorpay: publishable_key → key_id, secret_key → key_secret.
            if ($row->gateway === 'razorpay' && ! empty($creds['secret_key'] ?? '')) {
                $keyId = $creds['publishable_key'] ?? $creds['key_id'] ?? '';
                $keySecret = $creds['secret_key'] ?? $creds['key_secret'] ?? '';
                if ($keyId !== '' && $keySecret !== '') {
                    $this->gateways['razorpay'] = new RazorpayGateway(
                        $keyId,
                        $keySecret,
                        $creds['webhook_secret'] ?? ''
                    );
                }
            }
            // Cashfree: publishable_key → x-client-id, secret_key → x-client-secret.
            if ($row->gateway === 'cashfree' && ! empty($creds['secret_key'] ?? '')) {
                $clientId = $creds['publishable_key'] ?? $creds['client_id'] ?? '';
                $clientSecret = $creds['secret_key'] ?? $creds['client_secret'] ?? '';
                if ($clientId !== '' && $clientSecret !== '') {
                    $this->gateways['cashfree'] = new CashfreeGateway(
                        $clientId,
                        $clientSecret,
                        (bool) $row->test_mode,
                        $successUrl
                    );
                }
            }
            // Tap: secret_key → secret API key (also used to verify the webhook hashstring).
            if ($row->gateway === 'tap' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['tap'] = new TapGateway(
                    $creds['secret_key'],
                    $successUrl,
                    $cancelUrl,
                    $tapWebhookUrl
                );
            }
            // Paystack: publishable_key → public key, secret_key → secret key.
            if ($row->gateway === 'paystack' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['paystack'] = new PaystackGateway(
                    $creds['secret_key'],
                    $creds['publishable_key'] ?? $creds['public_key'] ?? '',
                    $successUrl,
                    $cancelUrl
                );
            }
            // Xendit: secret_key → API secret, webhook_secret → x-callback-token.
            if ($row->gateway === 'xendit' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['xendit'] = new XenditGateway(
                    $creds['secret_key'],
                    $creds['webhook_secret'] ?? '',
                    $successUrl,
                    $cancelUrl
                );
            }
            // Paymob: secret_key → api_key, webhook_secret → hmac_secret,
            //         publishable_key → integration_id (numeric), extra → iframe_id.
            if ($row->gateway === 'paymob' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['paymob'] = new PaymobGateway(
                    $creds['secret_key'],
                    $creds['webhook_secret'] ?? '',
                    (int) ($creds['publishable_key'] ?? $creds['integration_id'] ?? 0),
                    $creds['iframe_id'] ?? '',
                    $successUrl,
                    $cancelUrl
                );
            }
            // MyFatoorah: secret_key → api_key.
            if ($row->gateway === 'myfatoorah' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['myfatoorah'] = new MyFatoorahGateway(
                    $creds['secret_key'],
                    (bool) $row->test_mode,
                    $successUrl,
                    $cancelUrl
                );
            }
            // Mollie: secret_key → API key (its prefix, test_/live_, selects the environment).
            if ($row->gateway === 'mollie' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['mollie'] = new MollieGateway(
                    $creds['secret_key'],
                    $successUrl,
                    $cancelUrl
                );
            }
            // Square: secret_key → access token, publishable_key → location id,
            //         webhook_secret → webhook signature key.
            if ($row->gateway === 'square' && ! empty($creds['secret_key'] ?? '') && ! empty($creds['publishable_key'] ?? '')) {
                $this->gateways['square'] = new SquareGateway(
                    $creds['secret_key'],
                    $creds['publishable_key'],
                    $creds['webhook_secret'] ?? '',
                    (bool) $row->test_mode,
                    $successUrl,
                    $cancelUrl
                );
            }
            // iyzico: publishable_key → API key, secret_key → secret key,
            //         webhook_secret → merchant id (needed to verify X-IYZ-SIGNATURE-V3).
            if ($row->gateway === 'iyzico' && ! empty($creds['secret_key'] ?? '') && ! empty($creds['publishable_key'] ?? '')) {
                $this->gateways['iyzico'] = new IyzicoGateway(
                    $creds['publishable_key'],
                    $creds['secret_key'],
                    $creds['webhook_secret'] ?? $creds['merchant_id'] ?? '',
                    (bool) $row->test_mode,
                    $successUrl,
                    $cancelUrl
                );
            }
            // Mercado Pago: secret_key → access token, webhook_secret → signing secret.
            if ($row->gateway === 'mercadopago' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['mercadopago'] = new MercadoPagoGateway(
                    $creds['secret_key'],
                    $creds['webhook_secret'] ?? '',
                    $successUrl,
                    $cancelUrl
                );
            }
        }
    }

    private function registerFromConfig(): void
    {
        $config = config('billing.gateways', []);

        if (! isset($this->gateways['stripe']) && ! empty($config['stripe']['enabled']) && ($config['stripe']['secret_key'] ?? '')) {
            $this->gateways['stripe'] = new StripeGateway(
                $config['stripe']['secret_key'] ?? '',
                $config['stripe']['webhook_secret'] ?? '',
                $config['stripe']['success_url'] ?? (rtrim(config('app.url'), '/').'/app/billing?checkout=success'),
                $config['stripe']['cancel_url'] ?? (rtrim(config('app.url'), '/').'/app/pricing?checkout=canceled')
            );
        }

        if (! isset($this->gateways['paypal']) && ! empty($config['paypal']['enabled']) && ($config['paypal']['client_id'] ?? '')) {
            $this->gateways['paypal'] = new PayPalGateway(
                $config['paypal']['client_id'] ?? '',
                $config['paypal']['client_secret'] ?? '',
                (bool) ($config['paypal']['sandbox'] ?? true),
                $config['paypal']['success_url'] ?? '',
                $config['paypal']['cancel_url'] ?? '',
                $config['paypal']['webhook_id'] ?? ''
            );
        }

        if (! isset($this->gateways['paddle']) && ! empty($config['paddle']['enabled']) && ($config['paddle']['api_key'] ?? '')) {
            $this->gateways['paddle'] = new PaddleGateway(
                $config['paddle']['api_key'] ?? '',
                $config['paddle']['environment'] ?? 'sandbox',
                $config['paddle']['success_url'] ?? '',
                $config['paddle']['cancel_url'] ?? '',
                $config['paddle']['webhook_secret'] ?? ''
            );
        }

        $appUrl = config('app.url', '');
        $successUrl = rtrim($appUrl, '/').'/app/billing?checkout=success';
        $cancelUrl = rtrim($appUrl, '/').'/app/pricing?checkout=canceled';

        if (! isset($this->gateways['razorpay']) && ! empty($config['razorpay']['enabled']) && ($config['razorpay']['key_id'] ?? '')) {
            $this->gateways['razorpay'] = new RazorpayGateway(
                $config['razorpay']['key_id'] ?? '',
                $config['razorpay']['key_secret'] ?? '',
                $config['razorpay']['webhook_secret'] ?? ''
            );
        }

        if (! isset($this->gateways['cashfree']) && ! empty($config['cashfree']['enabled']) && ($config['cashfree']['client_id'] ?? '')) {
            $this->gateways['cashfree'] = new CashfreeGateway(
                $config['cashfree']['client_id'] ?? '',
                $config['cashfree']['client_secret'] ?? '',
                (bool) ($config['cashfree']['sandbox'] ?? true),
                $config['cashfree']['return_url'] ?? $successUrl
            );
        }

        if (! isset($this->gateways['tap']) && ! empty($config['tap']['enabled']) && ($config['tap']['secret_key'] ?? '')) {
            $this->gateways['tap'] = new TapGateway(
                $config['tap']['secret_key'] ?? '',
                $config['tap']['success_url'] ?? $successUrl,
                $config['tap']['cancel_url'] ?? $cancelUrl,
                rtrim($appUrl, '/').'/webhooks/tap'
            );
        }

        if (! isset($this->gateways['paystack']) && ! empty($config['paystack']['enabled']) && ($config['paystack']['secret_key'] ?? '')) {
            $this->gateways['paystack'] = new PaystackGateway(
                $config['paystack']['secret_key'] ?? '',
                $config['paystack']['public_key'] ?? '',
                $config['paystack']['success_url'] ?? $successUrl,
                $config['paystack']['cancel_url'] ?? $cancelUrl
            );
        }

        if (! isset($this->gateways['xendit']) && ! empty($config['xendit']['enabled']) && ($config['xendit']['secret_key'] ?? '')) {
            $this->gateways['xendit'] = new XenditGateway(
                $config['xendit']['secret_key'] ?? '',
                $config['xendit']['webhook_token'] ?? '',
                $config['xendit']['success_url'] ?? $successUrl,
                $config['xendit']['cancel_url'] ?? $cancelUrl
            );
        }

        if (! isset($this->gateways['paymob']) && ! empty($config['paymob']['enabled']) && ($config['paymob']['api_key'] ?? '')) {
            $this->gateways['paymob'] = new PaymobGateway(
                $config['paymob']['api_key'] ?? '',
                $config['paymob']['hmac_secret'] ?? '',
                (int) ($config['paymob']['integration_id'] ?? 0),
                $config['paymob']['iframe_id'] ?? '',
                $config['paymob']['success_url'] ?? $successUrl,
                $config['paymob']['cancel_url'] ?? $cancelUrl
            );
        }

        if (! isset($this->gateways['myfatoorah']) && ! empty($config['myfatoorah']['enabled']) && ($config['myfatoorah']['api_key'] ?? '')) {
            $this->gateways['myfatoorah'] = new MyFatoorahGateway(
                $config['myfatoorah']['api_key'] ?? '',
                (bool) ($config['myfatoorah']['sandbox'] ?? true),
                $config['myfatoorah']['success_url'] ?? $successUrl,
                $config['myfatoorah']['cancel_url'] ?? $cancelUrl
            );
        }

        if (! isset($this->gateways['mollie']) && ! empty($config['mollie']['enabled']) && ($config['mollie']['api_key'] ?? '')) {
            $this->gateways['mollie'] = new MollieGateway(
                $config['mollie']['api_key'] ?? '',
                $config['mollie']['success_url'] ?? $successUrl,
                $config['mollie']['cancel_url'] ?? $cancelUrl
            );
        }

        if (! isset($this->gateways['square']) && ! empty($config['square']['enabled']) && ($config['square']['access_token'] ?? '') && ($config['square']['location_id'] ?? '')) {
            $this->gateways['square'] = new SquareGateway(
                $config['square']['access_token'] ?? '',
                $config['square']['location_id'] ?? '',
                $config['square']['signature_key'] ?? '',
                (bool) ($config['square']['sandbox'] ?? true),
                $config['square']['success_url'] ?? $successUrl,
                $config['square']['cancel_url'] ?? $cancelUrl
            );
        }

        if (! isset($this->gateways['iyzico']) && ! empty($config['iyzico']['enabled']) && ($config['iyzico']['api_key'] ?? '') && ($config['iyzico']['secret_key'] ?? '')) {
            $this->gateways['iyzico'] = new IyzicoGateway(
                $config['iyzico']['api_key'] ?? '',
                $config['iyzico']['secret_key'] ?? '',
                $config['iyzico']['merchant_id'] ?? '',
                (bool) ($config['iyzico']['sandbox'] ?? true),
                $config['iyzico']['success_url'] ?? $successUrl,
                $config['iyzico']['cancel_url'] ?? $cancelUrl
            );
        }

        if (! isset($this->gateways['mercadopago']) && ! empty($config['mercadopago']['enabled']) && ($config['mercadopago']['access_token'] ?? '')) {
            $this->gateways['mercadopago'] = new MercadoPagoGateway(
                $config['mercadopago']['access_token'] ?? '',
                $config['mercadopago']['webhook_secret'] ?? '',
                $config['mercadopago']['success_url'] ?? $successUrl,
                $config['mercadopago']['cancel_url'] ?? $cancelUrl
            );
        }
    }

    /**
     * @return array<string, BillingGatewayInterface>
     */
    public function all(): array
    {
        return $this->gateways;
    }

    /**
     * Only gateways that are configured (credentials present).
     *
     * @return array<string, BillingGatewayInterface>
     */
    public function configured(): array
    {
        return array_filter($this->gateways, fn (BillingGatewayInterface $g) => $g->isConfigured());
    }

    public function get(string $key): ?BillingGatewayInterface
    {
        return $this->gateways[$key] ?? null;
    }

    /**
     * @return array<array{key: string, name: string, configured: bool}>
     */
    public function listForFrontend(): array
    {
        $out = [];
        $labels = [
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
            'paddle' => 'Paddle',
            'razorpay' => 'Razorpay',
            'cashfree' => 'Cashfree',
            'tap' => 'Tap',
            'paystack' => 'Paystack',
            'xendit' => 'Xendit',
            'paymob' => 'Paymob',
            'myfatoorah' => 'MyFatoorah',
            'mollie' => 'Mollie',
            'square' => 'Square',
            'iyzico' => 'iyzico',
            'mercadopago' => 'Mercado Pago',
        ];
        foreach ($labels as $key => $label) {
            $g = $this->gateways[$key] ?? null;
            $out[] = [
                'key' => $key,
                'name' => $g ? $g->name() : $label,
                'configured' => $g ? $g->isConfigured() : false,
            ];
        }

        return $out;
    }
}
