<?php

use App\Http\Controllers\Panel\AuthController;
use App\Http\Controllers\Panel\IntakeSessionPanelController;
use App\Http\Controllers\Panel\SupportUserController;
use App\Http\Controllers\Panel\TicketPanelController;
use App\Http\Controllers\Panel\TelegramWebhookDiagnosticsController;
use App\Http\Middleware\EnsureSupportUserIsActive;
use App\Http\Middleware\RequireHttps;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => config('app.name'),
    'status' => 'ok',
    'panel' => url('/panel'),
]));

Route::middleware([RequireHttps::class, 'guest:web'])->group(function (): void {
    Route::get('/panel/login', [AuthController::class, 'create'])->name('login');
    Route::post('/panel/login', [AuthController::class, 'store'])->middleware('throttle:20,1')->name('panel.login');
});

Route::prefix('panel')->name('panel.')->middleware([RequireHttps::class, 'auth:web', EnsureSupportUserIsActive::class, 'auth.session'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/', [TicketPanelController::class, 'index'])->name('index');
    Route::get('/report', [TicketPanelController::class, 'report'])->name('report');
    Route::get('/sessions', [IntakeSessionPanelController::class, 'index'])->name('sessions.index');
    Route::get('/telegram-webhooks', [TelegramWebhookDiagnosticsController::class, 'index'])->name('telegram-webhooks.index');
    Route::get('/telegram-webhooks/{trace}', [TelegramWebhookDiagnosticsController::class, 'show'])->name('telegram-webhooks.show');
    Route::post('/sessions/{session}/close', [IntakeSessionPanelController::class, 'close'])->name('sessions.close');
    Route::get('/tickets/{ticket}', [TicketPanelController::class, 'show'])->name('tickets.show');
    Route::post('/tickets/{ticket}/close', [TicketPanelController::class, 'close'])->name('tickets.close');

    Route::get('/users', [SupportUserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [SupportUserController::class, 'create'])->name('users.create');
    Route::post('/users', [SupportUserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [SupportUserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [SupportUserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [SupportUserController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{user}/avatar', [SupportUserController::class, 'updateAvatar'])->name('users.avatar');
});
