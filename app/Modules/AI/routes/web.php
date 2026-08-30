<?php

use App\Modules\AI\Http\Controllers\AiChatbotController;
use App\Modules\AI\Http\Controllers\AiKnowledgeBaseController;
use App\Modules\AI\Http\Controllers\AiProviderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/ai')->name('client.ai.')->group(function () {
    // Provider configs
    Route::get('/providers', [AiProviderController::class, 'index'])->name('providers.index');
    Route::put('/providers/{provider}', [AiProviderController::class, 'update'])->name('providers.update');

    // Knowledge bases
    Route::get('/knowledge-bases', [AiKnowledgeBaseController::class, 'index'])->name('knowledge-bases.index');
    Route::post('/knowledge-bases', [AiKnowledgeBaseController::class, 'store'])->name('knowledge-bases.store')->middleware('limit:knowledge_bases,knowledge_bases');
    Route::get('/knowledge-bases/{kb}', [AiKnowledgeBaseController::class, 'show'])->name('knowledge-bases.show');
    Route::post('/knowledge-bases/{kb}/documents', [AiKnowledgeBaseController::class, 'addDocument'])->name('knowledge-bases.documents.add');
    Route::post('/documents/{document}/reindex', [AiKnowledgeBaseController::class, 'reindex'])->name('documents.reindex');
    Route::delete('/documents/{document}', [AiKnowledgeBaseController::class, 'destroyDocument'])->name('documents.destroy');

    // Chatbots
    Route::get('/chatbots', [AiChatbotController::class, 'index'])->name('chatbots.index');
    Route::post('/chatbots', [AiChatbotController::class, 'store'])->name('chatbots.store');
    Route::put('/chatbots/{chatbot}', [AiChatbotController::class, 'update'])->name('chatbots.update');
    Route::delete('/chatbots/{chatbot}', [AiChatbotController::class, 'destroy'])->name('chatbots.destroy');
    Route::post('/chatbots/{chatbot}/playground', [AiChatbotController::class, 'playground'])->name('chatbots.playground')->middleware('limit:ai_tokens_per_month,ai_tokens');
});
