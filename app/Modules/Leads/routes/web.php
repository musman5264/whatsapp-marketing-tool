<?php

use App\Modules\Leads\Http\Controllers\LeadController;
use App\Modules\Leads\Http\Controllers\LeadPipelineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/leads')->name('client.leads.')->group(function () {
    Route::get('/', [LeadController::class, 'index'])->name('index');
    Route::post('/scrape', [LeadController::class, 'scrape'])->name('scrape')->middleware('limit:lead_credits_per_month,lead_credits');
    Route::post('/push-to-contacts', [LeadController::class, 'pushToContacts'])->name('push-to-contacts');

    // Pipeline board. Declared before /{lead} so "pipeline" is not swallowed as an id.
    Route::prefix('pipeline')->name('pipeline.')->group(function () {
        Route::get('/', [LeadPipelineController::class, 'index'])->name('index');
        Route::post('/rescore', [LeadPipelineController::class, 'rescore'])->name('rescore');
        Route::put('/scoring', [LeadPipelineController::class, 'updateScoring'])->name('scoring.update');
        Route::post('/stages', [LeadPipelineController::class, 'storeStage'])->name('stages.store');
        Route::put('/stages/reorder', [LeadPipelineController::class, 'reorderStages'])->name('stages.reorder');
        Route::put('/stages/{stage}', [LeadPipelineController::class, 'updateStage'])->name('stages.update');
        Route::delete('/stages/{stage}', [LeadPipelineController::class, 'destroyStage'])->name('stages.destroy');
        Route::put('/{lead}/move', [LeadPipelineController::class, 'move'])->name('move');
        Route::post('/{lead}/activities', [LeadPipelineController::class, 'storeActivity'])->name('activities.store');
    });

    Route::delete('/{lead}', [LeadController::class, 'destroy'])->name('destroy');
});
