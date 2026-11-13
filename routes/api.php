<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\DatabaseController;
use App\Http\Controllers\Api\V1\DnsController;
use App\Http\Controllers\Api\V1\DomainController;
use App\Http\Controllers\Api\V1\MailboxController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PackageController;
use App\Http\Middleware\AuthenticateWithLoginKey;
use Illuminate\Support\Facades\Route;

// The API: every request carries a login key ("Authorization: Bearer
// rxk_..."), made in the panel under Settings > Login keys, and may only do
// what that key's permissions and the account itself allow.
Route::prefix('v1')->name('api.v1.')->middleware([AuthenticateWithLoginKey::class, 'throttle:api'])->group(function () {
    Route::get('me', MeController::class)->name('me');

    // Administrators and resellers: their hosting accounts.
    Route::middleware('role:admin,reseller')->group(function () {
        Route::get('packages', [PackageController::class, 'index'])->middleware('login-key:packages:read')->name('packages.index');

        Route::middleware('login-key:accounts:read')->group(function () {
            Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
            Route::get('accounts/{account:username}', [AccountController::class, 'show'])->name('accounts.show');
        });

        Route::middleware('login-key:accounts:write')->group(function () {
            Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
            Route::post('accounts/{account:username}/suspend', [AccountController::class, 'suspend'])->name('accounts.suspend');
            Route::post('accounts/{account:username}/unsuspend', [AccountController::class, 'unsuspend'])->name('accounts.unsuspend');
            Route::put('accounts/{account:username}/package', [AccountController::class, 'updatePackage'])->name('accounts.package.update');
            Route::delete('accounts/{account:username}', [AccountController::class, 'destroy'])->name('accounts.destroy');
        });
    });

    // Hosting accounts: their websites.
    Route::middleware('role:user')->group(function () {
        Route::get('domains', [DomainController::class, 'index'])->middleware('login-key:domains:read')->name('domains.index');
        Route::post('domains', [DomainController::class, 'store'])->middleware('login-key:domains:write')->name('domains.store');
        Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->middleware('login-key:domains:write')->name('domains.destroy');

        Route::get('databases', [DatabaseController::class, 'index'])->middleware('login-key:databases:read')->name('databases.index');
        Route::post('databases', [DatabaseController::class, 'store'])->middleware('login-key:databases:write')->name('databases.store');
        Route::delete('databases/{database}', [DatabaseController::class, 'destroy'])->middleware('login-key:databases:write')->name('databases.destroy');
        Route::get('database-users', [DatabaseController::class, 'users'])->middleware('login-key:databases:read')->name('database-users.index');
        Route::post('database-users', [DatabaseController::class, 'storeUser'])->middleware('login-key:databases:write')->name('database-users.store');
        Route::delete('database-users/{databaseUser}', [DatabaseController::class, 'destroyUser'])->middleware('login-key:databases:write')->name('database-users.destroy');

        Route::get('mailboxes', [MailboxController::class, 'index'])->middleware('login-key:email:read')->name('mailboxes.index');
        Route::post('mailboxes', [MailboxController::class, 'store'])->middleware('login-key:email:write')->name('mailboxes.store');
        Route::delete('mailboxes/{mailbox}', [MailboxController::class, 'destroy'])->middleware('login-key:email:write')->name('mailboxes.destroy');

        Route::get('dns/zones', [DnsController::class, 'index'])->middleware('login-key:dns:read')->name('dns.zones.index');
        Route::post('dns/zones/{dnsZone}/records', [DnsController::class, 'store'])->middleware('login-key:dns:write')->name('dns.records.store');
        Route::delete('dns/records/{dnsRecord}', [DnsController::class, 'destroy'])->middleware('login-key:dns:write')->name('dns.records.destroy');
    });
});
