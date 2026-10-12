<?php

/**
 * Webhook intake routes – no CSRF, no auth.
 *
 * All webhook endpoints are public and protected by signature verification
 * performed inside each controller.
 *
 * Each route:
 *   GET  – used for webhook verification (hub.challenge / verify_token)
 *   POST – receives inbound events
 */

use App\Http\Controllers\Webhooks\AutomationWebhookController;
use App\Modules\Broadcasting\Http\Controllers\EmailTrackingController;
use App\Modules\Broadcasting\Http\Controllers\SmsStatusWebhookController;
use App\Modules\Ecommerce\Http\Controllers\EcommerceOAuthController;
use App\Modules\Ecommerce\Http\Controllers\EcommerceWebhookController;
use App\Modules\Inbox\Http\Controllers\MetaWebhookController;
use App\Modules\Whatsapp\Http\Controllers\WhatsappWebhookController;
use App\Modules\WhatsappWeb\Http\Controllers\WhatsappWebWebhookController;
use Illuminate\Support\Facades\Route;

// ─── WhatsApp Cloud API ──────────────────────────────────────────────────────
Route::middleware('throttle:webhooks')->group(function () {
    Route::prefix('webhooks/whatsapp')->name('webhooks.whatsapp.')->group(function () {
        // Global endpoint for embedded-signup WABAs (shared Meta App callback URL).
        // Must be declared before /{token} so "global" is not captured as a token.
        Route::get('/global', [WhatsappWebhookController::class, 'verifyGlobal'])->name('global.verify');
        Route::post('/global', [WhatsappWebhookController::class, 'receiveGlobal'])->name('global.receive');

        // Per-WABA token endpoints (used by manually configured WABAs).
        Route::get('/{token}', [WhatsappWebhookController::class, 'verify'])->name('verify');
        Route::post('/{token}', [WhatsappWebhookController::class, 'receive'])->name('receive');
    });

    // ─── WhatsApp Web (QR / unofficial engine — WAHA) ───────────────────────────
    Route::post('webhooks/whatsapp-web/{token}', [WhatsappWebWebhookController::class, 'receive'])
        ->name('webhooks.whatsapp-web.receive');

    // ─── Meta (Instagram + Messenger) ───────────────────────────────────────────
    Route::prefix('webhooks/meta')->name('webhooks.meta.')->group(function () {
        Route::get('/{token}', [MetaWebhookController::class, 'verify'])->name('verify');
        Route::post('/{token}', [MetaWebhookController::class, 'receive'])->name('receive');
    });

    // ─── SMS delivery status (Twilio, Nexmo/Vonage, MessageBird) ─────────────────
    Route::post('webhooks/sms/{provider}', [SmsStatusWebhookController::class, 'handle'])
        ->name('webhooks.sms.status')
        ->where('provider', 'twilio|nexmo|messagebird|plivo|telnyx|infobip|clicksend|smsbd|reve|bulksmsbd|sms_dot_bd|mimsms|fast2sms');

    // ─── Automation inbound webhook trigger ─────────────────────────────────────
    Route::post('webhooks/automation/{trigger_token}', [AutomationWebhookController::class, 'receive'])
        ->name('webhooks.automation.receive');

    // ─── Ecommerce (Shopify / WooCommerce) per-store webhooks ────────────────────
    Route::post('webhooks/ecommerce/shopify/{store}', [EcommerceWebhookController::class, 'shopify'])
        ->name('webhooks.ecommerce.shopify');
    Route::post('webhooks/ecommerce/woocommerce/{store}', [EcommerceWebhookController::class, 'woocommerce'])
        ->name('webhooks.ecommerce.woocommerce');
    Route::post('webhooks/ecommerce/bigcommerce/{store}', [EcommerceWebhookController::class, 'bigcommerce'])
        ->name('webhooks.ecommerce.bigcommerce');

    // WooCommerce auth endpoint posts the API keys here (server-to-server, no auth/CSRF).
    Route::post('webhooks/ecommerce/woo-auth', [EcommerceOAuthController::class, 'woocommerceCallback'])
        ->name('webhooks.ecommerce.woo_auth');

    // ─── Email open tracking pixel ───────────────────────────────────────────────
    Route::get('track/email/{token}/open.gif', [EmailTrackingController::class, 'open'])
        ->name('track.email.open');

    // ─── Email click tracking redirect (signed URL — prevents open-redirect abuse) ──
    Route::get('track/email/{token}/click', [EmailTrackingController::class, 'click'])
        ->name('track.email.click');

    // ─── Email one-click unsubscribe (RFC 2369 / RFC 8058) ───────────────────────
    Route::match(['get', 'post'], 'track/email/{token}/unsubscribe', [EmailTrackingController::class, 'unsubscribe'])
        ->name('track.email.unsubscribe');
}); // end throttle:webhooks
