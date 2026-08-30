<?php

use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Client\ApiTokenController;
use App\Http\Controllers\Client\AuditLogController as ClientAuditLogController;
use App\Http\Controllers\Client\BillingController;
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Client\InvitationController;
use App\Http\Controllers\Client\MediaController;
use App\Http\Controllers\Client\NotificationController;
use App\Http\Controllers\Client\OnboardingController;
use App\Http\Controllers\Client\SearchController;
use App\Http\Controllers\Client\Settings\DataExportController;
use App\Http\Controllers\Client\SettingsController as ClientSettingsController;
use App\Http\Controllers\Client\SubscriptionController as ClientSubscriptionController;
use App\Http\Controllers\Client\SupportTicketController;
use App\Http\Controllers\Client\TeamController;
use App\Http\Controllers\Client\WebhookEndpointController;
use App\Http\Controllers\Client\WebPushController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['verified'])->group(function () {
    // Dashboard
    Route::get('/dashboard', ClientDashboardController::class)->name('dashboard');

    // Subscription
    Route::get('/subscription', [ClientSubscriptionController::class, 'show'])->name('subscription.show');
    Route::post('/subscription/change-plan', [ClientSubscriptionController::class, 'changePlan'])->name('subscription.change-plan');
    Route::get('/subscription/invoice/{transaction}', [ClientSubscriptionController::class, 'invoiceDownload'])->name('subscription.invoice');
    Route::delete('/subscription', [ClientSubscriptionController::class, 'destroy'])->name('subscription.destroy');

    // Coupon validation
    Route::post('/coupon/check', [ClientSubscriptionController::class, 'couponCheck'])->name('coupon.check');

    // Billing & Pricing
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::get('/pricing', [PricingController::class, 'index'])->name('pricing');
    Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');

    // Team management (client admins only)
    Route::get('/team', [TeamController::class, 'index'])->name('team.index');
    Route::post('/team', [TeamController::class, 'store'])->name('team.store');
    Route::put('/team/{member}', [TeamController::class, 'update'])->name('team.update');
    Route::delete('/team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');

    // Audit log (client admins only)
    Route::get('/audit-log', [ClientAuditLogController::class, 'index'])->name('audit-log.index');

    // Settings
    Route::get('/settings', [ClientSettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings', [ClientSettingsController::class, 'update'])->name('settings.update');
    Route::get('/settings/notifications', [ClientSettingsController::class, 'notifications'])->name('settings.notifications');
    Route::get('/settings/data-export', [DataExportController::class, 'index'])->name('settings.data-export');
    Route::post('/settings/data-export', [DataExportController::class, 'store'])->name('settings.data-export.store');

    // Workspaces (switcher)
    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('/workspaces/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // 2FA
    Route::get('/profile/two-factor', [TwoFactorController::class, 'show'])->name('profile.2fa');
    Route::post('/profile/two-factor/enable', [TwoFactorController::class, 'enable'])->name('profile.2fa.enable');
    Route::post('/profile/two-factor/disable', [TwoFactorController::class, 'disable'])->name('profile.2fa.disable');
    Route::post('/profile/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateCodes'])->name('profile.2fa.recovery-codes');

    // Session management
    Route::get('/profile/sessions', [SessionController::class, 'index'])->name('profile.sessions');
    Route::delete('/profile/sessions', [SessionController::class, 'destroy'])->name('profile.sessions.destroy');

    // Team invitations (send/revoke, client admin only)
    Route::post('/invitations', [InvitationController::class, 'store'])->name('invitations.store');
    Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    // API Tokens & Docs
    Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::get('/api-docs', fn () => Inertia::render('client/Api/Docs'))->name('api-docs');

    // Media Library
    Route::get('/media', [MediaController::class, 'index'])->name('media.index');
    Route::post('/media', [MediaController::class, 'store'])->name('media.store');
    Route::delete('/media/{medium}', [MediaController::class, 'destroy'])->name('media.destroy');

    // Onboarding Wizard
    Route::get('/onboarding', [OnboardingController::class, 'show'])->name('onboarding.show');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');

    // Global Search (⌘K)
    Route::get('/search', [SearchController::class, 'search'])->name('search');

    // Support Tickets
    Route::get('/support', [SupportTicketController::class, 'index'])->name('support.index');
    Route::get('/support/create', [SupportTicketController::class, 'create'])->name('support.create');
    Route::post('/support', [SupportTicketController::class, 'store'])->name('support.store');
    Route::get('/support/{supportTicket}', [SupportTicketController::class, 'show'])->name('support.show');
    Route::post('/support/{supportTicket}/reply', [SupportTicketController::class, 'reply'])->name('support.reply');

    // In-app Notifications
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
    Route::post('/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('notification-preferences.update');

    // Webhook Endpoints
    Route::get('/webhooks', [WebhookEndpointController::class, 'index'])->name('webhooks.index');
    Route::post('/webhooks', [WebhookEndpointController::class, 'store'])->name('webhooks.store');
    Route::put('/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{webhookEndpoint}', [WebhookEndpointController::class, 'destroy'])->name('webhooks.destroy');
    Route::post('/webhooks/{webhookEndpoint}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])->name('webhooks.rotate-secret');
    Route::post('/webhooks/{webhookEndpoint}/test', [WebhookEndpointController::class, 'testDelivery'])->name('webhooks.test');
    Route::get('/webhooks/{webhookEndpoint}/deliveries', [WebhookEndpointController::class, 'deliveries'])->name('webhooks.deliveries');

    // Web Push subscriptions
    Route::post('/push/subscribe', [WebPushController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [WebPushController::class, 'unsubscribe'])->name('push.unsubscribe');
});
