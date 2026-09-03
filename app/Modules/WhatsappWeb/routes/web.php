<?php

use App\Modules\WhatsappWeb\Http\Controllers\WhatsappWebSessionController;
use Illuminate\Support\Facades\Route;

// Authenticated client routes — QR pairing lifecycle for a personal number.
Route::middleware(['web', 'client-app'])
    ->prefix('app/whatsapp-web')
    ->name('client.whatsapp-web.')
    ->group(function () {
        Route::post('/connect', [WhatsappWebSessionController::class, 'connect'])->name('connect');
        Route::get('/qr', [WhatsappWebSessionController::class, 'qr'])->name('qr');
        Route::get('/status', [WhatsappWebSessionController::class, 'status'])->name('status');
        Route::post('/settings', [WhatsappWebSessionController::class, 'updateSettings'])->name('settings');
        Route::delete('/disconnect', [WhatsappWebSessionController::class, 'disconnect'])->name('disconnect');
    });
