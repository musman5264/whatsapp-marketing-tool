<?php

use App\Modules\Broadcasting\Http\Controllers\CampaignController;
use App\Modules\Broadcasting\Http\Controllers\EmailAiController;
use App\Modules\Broadcasting\Http\Controllers\EmailServerController;
use App\Modules\Broadcasting\Http\Controllers\SmsProviderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/broadcasts')->name('client.')->group(function () {
    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::post('/campaigns/generate-email', [EmailAiController::class, 'generate'])->name('campaigns.generate-email');
    Route::post('/campaigns/improve-subject', [EmailAiController::class, 'improveSubject'])->name('campaigns.improve-subject');
    Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
    Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::post('/campaigns/draft', [CampaignController::class, 'storeDraft'])->name('campaigns.store-draft');
    Route::post('/campaigns/audience-preview', [CampaignController::class, 'audiencePreview'])->name('campaigns.audience-preview');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::get('/campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
    Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
    Route::post('/campaigns/{campaign}/test-send', [CampaignController::class, 'testSend'])->name('campaigns.test-send');
    Route::post('/campaigns/{campaign}/launch', [CampaignController::class, 'launch'])->name('campaigns.launch')->middleware('limit:campaigns_per_month,campaigns');
    Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause'])->name('campaigns.pause');
    Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy'])->name('campaigns.destroy');

    Route::get('/sms-gateways', [SmsProviderController::class, 'index'])->name('sms-gateways.index');
    Route::put('/sms-gateways/{provider}', [SmsProviderController::class, 'update'])->name('sms-gateways.update');
    Route::delete('/sms-gateways/{provider}', [SmsProviderController::class, 'destroy'])->name('sms-gateways.destroy');

    Route::get('/email-server', [EmailServerController::class, 'index'])->name('email-server.index');
    Route::post('/email-server', [EmailServerController::class, 'store'])->name('email-server.store');
    Route::put('/email-server', [EmailServerController::class, 'update'])->name('email-server.update');
    Route::delete('/email-server', [EmailServerController::class, 'destroy'])->name('email-server.destroy');
    Route::post('/email-server/test', [EmailServerController::class, 'testEmail'])->name('email-server.test');
});
