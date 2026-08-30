<?php

use App\Modules\Inbox\Http\Controllers\CannedReplyController;
use App\Modules\Inbox\Http\Controllers\ConversationActivityController;
use App\Modules\Inbox\Http\Controllers\InboxController;
use App\Modules\Inbox\Http\Controllers\InboxSetupController;
use App\Modules\Inbox\Http\Controllers\InternalNoteController;
use App\Modules\Inbox\Http\Controllers\LabelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/inbox')->name('client.inbox.')->group(function () {
    Route::get('/', [InboxController::class, 'index'])->name('index');
    Route::get('/contacts/search', [InboxController::class, 'contactSearch'])->name('contacts.search');
    Route::get('/channel-accounts', [InboxController::class, 'channelAccounts'])->name('channel-accounts');
    Route::get('/templates', [InboxController::class, 'templates'])->name('templates');
    Route::post('/conversations/{conversation}/upload-media', [InboxController::class, 'uploadMedia'])->name('upload-media');
    Route::get('/conversations/{conversation}/messages/{message}/media', [InboxController::class, 'serveMedia'])->name('message-media');
    Route::post('/conversations/start', [InboxController::class, 'startConversation'])->name('start');
    Route::get('/conversations/{conversation}', [InboxController::class, 'show'])->name('show');
    Route::post('/conversations/{conversation}/reply', [InboxController::class, 'reply'])->name('reply')->middleware('limit:whatsapp_messages_per_month,whatsapp_messages');
    Route::post('/conversations/{conversation}/share-product', [InboxController::class, 'shareProduct'])->name('share-product')->middleware('limit:whatsapp_messages_per_month,whatsapp_messages');
    Route::post('/conversations/{conversation}/assign', [InboxController::class, 'assign'])->name('assign');
    Route::post('/conversations/{conversation}/status', [InboxController::class, 'updateStatus'])->name('status');
    Route::post('/conversations/{conversation}/typing', [InboxController::class, 'typing'])->name('typing');
    Route::get('/conversations/{conversation}/activities', [ConversationActivityController::class, 'index'])->name('activities.index');
    Route::get('/conversations/{conversation}/notes', [InternalNoteController::class, 'index'])->name('notes.index');
    Route::post('/conversations/{conversation}/notes', [InternalNoteController::class, 'store'])->name('notes.store');
    Route::post('/conversations/{conversation}/handover', [InboxController::class, 'handover'])->name('handover');

    // Canned replies
    Route::get('/canned-replies', [CannedReplyController::class, 'index'])->name('canned-replies.index');
    Route::get('/canned-replies/list', [CannedReplyController::class, 'list'])->name('canned-replies.list');
    Route::post('/canned-replies', [CannedReplyController::class, 'store'])->name('canned-replies.store');
    Route::put('/canned-replies/{cannedReply}', [CannedReplyController::class, 'update'])->name('canned-replies.update');
    Route::delete('/canned-replies/{cannedReply}', [CannedReplyController::class, 'destroy'])->name('canned-replies.destroy');

    // Labels
    Route::get('/labels', [LabelController::class, 'index'])->name('labels.index');
    Route::post('/labels', [LabelController::class, 'store'])->name('labels.store');
    Route::put('/labels/{label}', [LabelController::class, 'update'])->name('labels.update');
    Route::delete('/labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');
    Route::post('/conversations/{conversation}/labels', [LabelController::class, 'attach'])->name('labels.attach');
    Route::delete('/conversations/{conversation}/labels/{label}', [LabelController::class, 'detach'])->name('labels.detach');

    // Channel account setup (Instagram / Messenger)
    Route::get('/setup', [InboxSetupController::class, 'index'])->name('setup');
    Route::post('/setup/embedded-signup/instagram', [InboxSetupController::class, 'embeddedSignupInstagram'])->name('setup.embedded-signup.instagram');
    Route::post('/setup/embedded-signup/messenger', [InboxSetupController::class, 'embeddedSignupMessenger'])->name('setup.embedded-signup.messenger');
    Route::post('/setup/manual/instagram', [InboxSetupController::class, 'manualInstagram'])->name('setup.manual.instagram');
    Route::post('/setup/manual/messenger', [InboxSetupController::class, 'manualMessenger'])->name('setup.manual.messenger');
    Route::patch('/setup/{channelAccount}/chatbot', [InboxSetupController::class, 'assignChatbot'])->name('setup.assign-chatbot');
    Route::get('/setup/{channelAccount}/webhook-health', [InboxSetupController::class, 'webhookHealth'])->name('setup.webhook-health');
    Route::post('/setup/{channelAccount}/resubscribe', [InboxSetupController::class, 'resubscribeWebhooks'])->name('setup.resubscribe');
    Route::delete('/setup/{channelAccount}', [InboxSetupController::class, 'destroy'])->name('setup.destroy');
});
