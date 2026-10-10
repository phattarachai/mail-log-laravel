<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Phattarachai\MailLogLaravel\Http\Controllers\AttachmentController;
use Phattarachai\MailLogLaravel\Http\Controllers\EventController;
use Phattarachai\MailLogLaravel\Http\Controllers\GroupController;
use Phattarachai\MailLogLaravel\Http\Controllers\ResendController;
use Phattarachai\MailLogLaravel\Http\Controllers\TestSendController;

Route::prefix((string) config('mail-log.ui.path', 'mail-log'))
    ->middleware(config('mail-log.ui.middleware', ['web']))
    ->name('mail-log.')
    ->group(function (): void {
        Route::get('/', [GroupController::class, 'index'])->name('index');
        Route::post('/test-send', [TestSendController::class, 'store'])->name('test-send');
        Route::get('/{group}', [GroupController::class, 'show'])->name('show');
        Route::delete('/{group}', [GroupController::class, 'destroy'])->name('destroy');
        Route::get('/{group}/attachments/{media}', [AttachmentController::class, 'show'])->name('attachment');
        Route::get('/{group}/events/{event}', [EventController::class, 'show'])->name('event');
        Route::post('/{group}/events/{event}/resend', [ResendController::class, 'store'])->name('resend');
    });
