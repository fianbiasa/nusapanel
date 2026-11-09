<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DatabaseUserController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\FtpAccountController;
use App\Http\Controllers\PhpMyAdminController;
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
        Route::post('domains/{domain}/certificate', [DomainController::class, 'installCertificate'])->name('domains.certificate.store');
        Route::put('domains/{domain}/force-https', [DomainController::class, 'updateForceHttps'])->name('domains.force-https.update');
        Route::put('domains/{domain}/php', [DomainController::class, 'updatePhpVersion'])->name('domains.php.update');
        Route::put('domains/{domain}/php-settings', [DomainController::class, 'updatePhpSettings'])->name('domains.php-settings.update');
        Route::get('databases', [DatabaseController::class, 'index'])->name('databases.index');
        Route::post('databases', [DatabaseController::class, 'store'])->name('databases.store');
        Route::delete('databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
        Route::post('databases/phpmyadmin', PhpMyAdminController::class)->middleware('throttle:10,1')->name('databases.phpmyadmin');
        Route::post('database-users', [DatabaseUserController::class, 'store'])->name('database-users.store');
        Route::put('database-users/{databaseUser}', [DatabaseUserController::class, 'update'])->name('database-users.update');
        Route::delete('database-users/{databaseUser}', [DatabaseUserController::class, 'destroy'])->name('database-users.destroy');
        Route::get('ftp', [FtpAccountController::class, 'index'])->name('ftp.index');
        Route::post('ftp', [FtpAccountController::class, 'store'])->name('ftp.store');
        Route::put('ftp/{ftpAccount}', [FtpAccountController::class, 'update'])->name('ftp.update');
        Route::delete('ftp/{ftpAccount}', [FtpAccountController::class, 'destroy'])->name('ftp.destroy');
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
