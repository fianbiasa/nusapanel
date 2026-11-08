<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\PointerController;
use App\Http\Controllers\SubdomainController;
use App\Http\Controllers\UrlRedirectController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('role:user')->group(function () {
        Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
        Route::post('domains', [DomainController::class, 'store'])->name('domains.store');
        Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
        Route::post('domains/{domain}/ssl', [DomainController::class, 'requestSsl'])->name('domains.ssl.store');
        Route::delete('domains/{domain}/ssl', [DomainController::class, 'removeSsl'])->name('domains.ssl.destroy');
        Route::put('domains/{domain}/force-https', [DomainController::class, 'updateForceHttps'])->name('domains.force-https.update');
        Route::post('subdomains', [SubdomainController::class, 'store'])->name('subdomains.store');
        Route::post('pointers', [PointerController::class, 'store'])->name('pointers.store');
        Route::get('redirects', [UrlRedirectController::class, 'index'])->name('redirects.index');
        Route::post('redirects', [UrlRedirectController::class, 'store'])->name('redirects.store');
        Route::delete('redirects/{urlRedirect}', [UrlRedirectController::class, 'destroy'])->name('redirects.destroy');
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/create', [AccountController::class, 'create'])->name('accounts.create');
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::post('accounts/{account}/suspend', [AccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('accounts/{account}/unsuspend', [AccountController::class, 'unsuspend'])->name('accounts.unsuspend');
        Route::put('accounts/{account}/package', [AccountController::class, 'updatePackage'])->name('accounts.package.update');
        Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
        Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
        Route::get('packages/create', [PackageController::class, 'create'])->name('packages.create');
        Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
        Route::get('packages/{package}/edit', [PackageController::class, 'edit'])->name('packages.edit');
        Route::put('packages/{package}', [PackageController::class, 'update'])->name('packages.update');
        Route::delete('packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
        Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    });
});

require __DIR__.'/settings.php';
