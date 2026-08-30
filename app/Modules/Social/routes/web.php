<?php

use App\Modules\Social\Http\Controllers\SocialAccountController;
use App\Modules\Social\Http\Controllers\SocialPostController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/social')->name('client.social.')->group(function () {
    Route::get('/accounts', [SocialAccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/connect/{network}', [SocialAccountController::class, 'connect'])->name('accounts.connect');
    Route::post('/accounts/manual/{network}', [SocialAccountController::class, 'manualConnect'])->name('accounts.manual');
    Route::get('/accounts/callback/{network}', [SocialAccountController::class, 'callback'])->name('oauth.callback');
    Route::delete('/accounts/{account}', [SocialAccountController::class, 'disconnect'])->name('accounts.disconnect');

    Route::get('/posts', [SocialPostController::class, 'index'])->name('posts.index');
    Route::get('/composer', [SocialPostController::class, 'composer'])->name('composer');
    Route::post('/posts', [SocialPostController::class, 'store'])->name('posts.store')->middleware('limit:social_posts_per_month,social_posts');
    Route::get('/posts/{post}/edit', [SocialPostController::class, 'edit'])->name('posts.edit');
    Route::put('/posts/{post}', [SocialPostController::class, 'update'])->name('posts.update');
    Route::delete('/posts/{post}', [SocialPostController::class, 'destroy'])->name('posts.destroy');
    Route::post('/posts/{post}/publish-now', [SocialPostController::class, 'publishNow'])->name('posts.publish-now');
    Route::post('/posts/{post}/cancel', [SocialPostController::class, 'cancel'])->name('posts.cancel');
    Route::post('/ai-generate', [SocialPostController::class, 'aiGenerate'])->name('ai-generate');
    Route::post('/ai-plan',    [SocialPostController::class, 'aiPlan'])->name('ai-plan');
    Route::post('/posts/bulk', [SocialPostController::class, 'bulkStore'])->name('posts.bulk')->middleware('limit:social_posts_per_month,social_posts');
    Route::get('/calendar', [SocialPostController::class, 'calendar'])->name('calendar');
});
