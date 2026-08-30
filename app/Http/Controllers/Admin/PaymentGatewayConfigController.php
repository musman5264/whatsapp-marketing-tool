<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PaymentGatewayConfigController extends Controller
{
    /** Gateways the admin panel can configure. */
    private const GATEWAYS = ['stripe', 'paypal', 'paddle', 'razorpay', 'cashfree', 'tap', 'paystack', 'paymob', 'myfatoorah', 'xendit', 'mollie', 'square', 'iyzico', 'mercadopago'];

    /** Display labels (falls back to ucfirst for anything missing). */
    private const LABELS = [
        'stripe' => 'Stripe',
        'paypal' => 'PayPal',
        'paddle' => 'Paddle',
        'razorpay' => 'Razorpay',
        'cashfree' => 'Cashfree',
        'tap' => 'Tap',
        'paystack' => 'Paystack',
        'paymob' => 'Paymob',
        'myfatoorah' => 'MyFatoorah',
        'xendit' => 'Xendit',
        'mollie' => 'Mollie',
        'square' => 'Square',
        'iyzico' => 'iyzico',
        'mercadopago' => 'Mercado Pago',
    ];

    public function index(): Response
    {
        $gateways = self::GATEWAYS;
        $configs = PaymentGatewayConfig::whereIn('gateway', $gateways)->get()->keyBy('gateway');

        $list = [];
        foreach ($gateways as $key) {
            $config = $configs->get($key);
            $list[] = [
                'gateway' => $key,
                'name' => self::LABELS[$key] ?? ucfirst($key),
                'enabled' => $config?->enabled ?? false,
                'test_mode' => $config?->test_mode ?? true,
                'configured' => $config?->hasActiveCredentials() ?? false,
            ];
        }

        return Inertia::render('Admin/PaymentGateways/Index', [
            'gateways' => $list,
        ]);
    }

    /**
     * Get one gateway config for editing (credentials masked for secrets).
     */
    public function show(string $gateway): JsonResponse
    {
        $this->validateGateway($gateway);
        $config = PaymentGatewayConfig::firstOrCreate(
            ['gateway' => $gateway],
            [
                'test_mode' => true,
                'enabled' => false,
                'credentials' => [
                    'test' => $this->defaultCredentialKeys($gateway),
                    'live' => $this->defaultCredentialKeys($gateway),
                ],
            ]
        );

        $credentials = $config->credentials ?? [];
        $test = $credentials['test'] ?? [];
        $live = $credentials['live'] ?? [];

        $data = [
            'gateway' => $config->gateway,
            'name' => self::LABELS[$config->gateway] ?? ucfirst($config->gateway),
            'test_mode' => $config->test_mode,
            'enabled' => $config->enabled,
            'test_publishable_key' => $test['publishable_key'] ?? '',
            'test_secret_key' => ($test['secret_key'] ?? '') !== '' ? '••••••••' : '',
            'test_webhook_secret' => ($test['webhook_secret'] ?? '') !== '' ? '••••••••' : '',
            'live_publishable_key' => $live['publishable_key'] ?? '',
            'live_secret_key' => ($live['secret_key'] ?? '') !== '' ? '••••••••' : '',
            'live_webhook_secret' => ($live['webhook_secret'] ?? '') !== '' ? '••••••••' : '',
        ];

        return response()->json($data);
    }

    public function update(Request $request, string $gateway): RedirectResponse
    {
        $this->validateGateway($gateway);
        $config = PaymentGatewayConfig::firstOrCreate(
            ['gateway' => $gateway],
            [
                'test_mode' => true,
                'enabled' => false,
                'credentials' => [
                    'test' => $this->defaultCredentialKeys($gateway),
                    'live' => $this->defaultCredentialKeys($gateway),
                ],
            ]
        );

        $rules = [
            'test_mode' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'test_publishable_key' => ['nullable', 'string', 'max:512'],
            'test_secret_key' => ['nullable', 'string', 'max:512'],
            'test_webhook_secret' => ['nullable', 'string', 'max:512'],
            'live_publishable_key' => ['nullable', 'string', 'max:512'],
            'live_secret_key' => ['nullable', 'string', 'max:512'],
            'live_webhook_secret' => ['nullable', 'string', 'max:512'],
        ];

        $validated = $request->validate($rules);

        if (! empty($validated['enabled'])) {
            $isTest = (bool) $validated['test_mode'];
            $secret = $isTest ? ($validated['test_secret_key'] ?? '') : ($validated['live_secret_key'] ?? '');
            if (preg_match('/^•+$/', (string) $secret) || $secret === '') {
                $existing = $config->credentials ?? [];
                $mode = $isTest ? 'test' : 'live';
                $hasStored = ! empty($existing[$mode]['secret_key'] ?? '');
                if (! $hasStored) {
                    $field = $isTest ? 'test_secret_key' : 'live_secret_key';
                    throw ValidationException::withMessages([$field => __('Secret key is required when enabling the gateway.')]);
                }
            }
        }

        $existing = $config->credentials ?? [
            'test' => $this->defaultCredentialKeys($gateway),
            'live' => $this->defaultCredentialKeys($gateway),
        ];

        $credentialKeys = ['publishable_key', 'secret_key', 'webhook_secret'];
        $test = $existing['test'] ?? [];
        $live = $existing['live'] ?? [];

        foreach (['test', 'live'] as $mode) {
            $prefix = $mode.'_';
            foreach ($credentialKeys as $k) {
                $field = $prefix.$k;
                $v = $validated[$field] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                if (preg_match('/^•+$/', (string) $v)) {
                    continue;
                }
                if ($mode === 'test') {
                    $test[$k] = $v;
                } else {
                    $live[$k] = $v;
                }
            }
        }

        $config->update([
            'test_mode' => (bool) $validated['test_mode'],
            'enabled' => (bool) $validated['enabled'],
            'credentials' => ['test' => $test, 'live' => $live],
        ]);

        return back()->with('success', __('Payment gateway updated.'));
    }

    private function validateGateway(string $gateway): void
    {
        if (! in_array($gateway, self::GATEWAYS, true)) {
            abort(404);
        }
    }

    private function defaultCredentialKeys(string $gateway): array
    {
        return ['publishable_key' => '', 'secret_key' => '', 'webhook_secret' => ''];
    }
}
