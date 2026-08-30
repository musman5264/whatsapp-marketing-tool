<?php

namespace App\Http\Controllers;

use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    // Headers that must never appear in logs
    private const SENSITIVE_HEADERS = [
        'authorization',
        'stripe-signature',
        'paypal-transmission-sig',
        'paypal-cert-url',
        'paddle-signature',
        'x-razorpay-signature',
        'x-webhook-signature',
        'x-paystack-signature',
        'x-callback-token',
        'x-square-hmacsha256-signature',
        'x-iyz-signature-v3',
        'x-signature',
        'hashstring',
        'x-hub-signature',
        'x-hub-signature-256',
        'cookie',
    ];

    public function __construct(
        private BillingGatewayRegistry $gateways
    ) {}

    /**
     * Stripe webhook (no auth; verified by signature inside gateway).
     */
    public function stripe(Request $request): Response
    {
        Log::channel('single')->info('Stripe webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('stripe');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * PayPal webhook.
     */
    public function paypal(Request $request): Response
    {
        Log::channel('single')->info('PayPal webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('paypal');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * Paddle webhook.
     */
    public function paddle(Request $request): Response
    {
        Log::channel('single')->info('Paddle webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('paddle');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * Razorpay webhook.
     */
    public function razorpay(Request $request): Response
    {
        Log::channel('single')->info('Razorpay webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('razorpay');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * Cashfree webhook.
     */
    public function cashfree(Request $request): Response
    {
        Log::channel('single')->info('Cashfree webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('cashfree');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * Tap webhook.
     */
    public function tap(Request $request): Response
    {
        Log::channel('single')->info('Tap webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('tap');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * Paystack webhook.
     */
    public function paystack(Request $request): Response
    {
        Log::channel('single')->info('Paystack webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('paystack');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * Xendit webhook.
     */
    public function xendit(Request $request): Response
    {
        Log::channel('single')->info('Xendit webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('xendit');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * Paymob webhook.
     */
    public function paymob(Request $request): Response
    {
        Log::channel('single')->info('Paymob webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('paymob');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * MyFatoorah webhook.
     */
    public function myfatoorah(Request $request): Response
    {
        Log::channel('single')->info('MyFatoorah webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('myfatoorah');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * Mollie webhook.
     */
    public function mollie(Request $request): Response
    {
        Log::channel('single')->info('Mollie webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('mollie');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * Square webhook.
     */
    public function square(Request $request): Response
    {
        Log::channel('single')->info('Square webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('square');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * iyzico webhook.
     */
    public function iyzico(Request $request): Response
    {
        Log::channel('single')->info('iyzico webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('iyzico');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * Mercado Pago webhook.
     */
    public function mercadopago(Request $request): Response
    {
        Log::channel('single')->info('Mercado Pago webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('mercadopago');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    private function safeHeaders(Request $request): array
    {
        return collect($request->headers->all())
            ->except(self::SENSITIVE_HEADERS)
            ->toArray();
    }
}
