<?php

use App\Http\Controllers\Settings\LoginKeyController;
use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    // The email is where a password reset goes, so changing it asks for the
    // password first, as the security page does: a stolen session cannot
    // take the account over by moving the email.
    Route::get('settings/profile', [ProfileController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->middleware(RequirePassword::class)
        ->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::put('settings/notifications/{type}', [NotificationController::class, 'update'])->whereIn('type', ['email', 'telegram'])->name('notifications.update');
    Route::delete('settings/notifications/{type}', [NotificationController::class, 'destroy'])->whereIn('type', ['email', 'telegram'])->name('notifications.destroy');
    Route::post('settings/notifications/{type}/test', [NotificationController::class, 'test'])->whereIn('type', ['email', 'telegram'])->middleware('throttle:6,1,notification-test')->name('notifications.test');

    // A login key works without the password or 2FA, so making and
    // deleting keys asks for the password first, as the security page does.
    Route::middleware(RequirePassword::class)->group(function () {
        Route::get('settings/login-keys', [LoginKeyController::class, 'index'])->name('login-keys.index');
        Route::post('settings/login-keys', [LoginKeyController::class, 'store'])->middleware('throttle:10,1,login-keys')->name('login-keys.store');
        Route::delete('settings/login-keys/{loginKey}', [LoginKeyController::class, 'destroy'])->name('login-keys.destroy');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
