<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DnsController as AdminDnsController;
use App\Http\Controllers\Admin\MailQueueController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\ResellerPackageController;
use App\Http\Controllers\Admin\ServerSettingController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\StatisticsController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\CatchAllController;
use App\Http\Controllers\CertificateRequestController;
use App\Http\Controllers\CronJobController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DatabaseToolsController;
use App\Http\Controllers\DatabaseUserController;
use App\Http\Controllers\DnsController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\DomainWebSettingsController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\FtpAccountController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\MailboxRulesController;
use App\Http\Controllers\MailClientConfigController;
use App\Http\Controllers\MailDnsCheckController;
use App\Http\Controllers\MailForwarderController;
use App\Http\Controllers\MailModeController;
use App\Http\Controllers\MailSslController;
use App\Http\Controllers\PhpMyAdminController;
use App\Http\Controllers\PointerController;
use App\Http\Controllers\SftpPasswordController;
use App\Http\Controllers\SubdomainController;
use App\Http\Controllers\UrlRedirectController;
use App\Http\Controllers\WebmailController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// What mail apps look up on their own for an address of a domain whose mail
// this server takes: Thunderbird (autoconfig.<domain>) and Outlook (sent to
// the hostname by _autodiscover._tcp). Nginx passes them on from port 80 of
// any name and from the hostname's port 443.
Route::get('mail/config-v1.1.xml', [MailClientConfigController::class, 'autoconfig'])
    ->middleware('throttle:60,1,mail-autoconfig')
    ->name('mail-client.autoconfig');
Route::get('.well-known/autoconfig/mail/config-v1.1.xml', [MailClientConfigController::class, 'autoconfig'])
    ->middleware('throttle:60,1,mail-autoconfig')
    ->name('mail-client.autoconfig.well-known');
Route::match(['get', 'post'], 'autodiscover/autodiscover.xml', [MailClientConfigController::class, 'autodiscover'])
    ->middleware('throttle:60,1,mail-autodiscover')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('mail-client.autodiscover');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::middleware('role:user')->group(function () {
        Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
        Route::post('domains', [DomainController::class, 'store'])->name('domains.store');
        Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
        Route::post('domains/{domain}/ssl', [DomainController::class, 'requestSsl'])->name('domains.ssl.store');
        Route::delete('domains/{domain}/ssl', [DomainController::class, 'removeSsl'])->name('domains.ssl.destroy');
        Route::post('domains/{domain}/certificate', [DomainController::class, 'installCertificate'])->name('domains.certificate.store');
        Route::post('domains/{domain}/certificate-request', [CertificateRequestController::class, 'store'])->name('domains.certificate-request.store');
        Route::delete('domains/{domain}/certificate-request', [CertificateRequestController::class, 'destroy'])->name('domains.certificate-request.destroy');
        Route::post('domains/{domain}/self-signed-certificate', [CertificateRequestController::class, 'selfSigned'])->name('domains.self-signed-certificate.store');
        Route::put('domains/{domain}/force-https', [DomainController::class, 'updateForceHttps'])->name('domains.force-https.update');
        Route::put('domains/{domain}/www', [DomainController::class, 'updateWww'])->name('domains.www.update');
        Route::put('domains/{domain}/php-enabled', [DomainController::class, 'updatePhpEnabled'])->name('domains.php-enabled.update');
        Route::put('domains/{domain}/document-root', [DomainController::class, 'updateDocumentRoot'])->name('domains.document-root.update');
        Route::put('domains/{domain}/php', [DomainController::class, 'updatePhpVersion'])->name('domains.php.update');
        Route::put('domains/{domain}/php-settings', [DomainController::class, 'updatePhpSettings'])->name('domains.php-settings.update');
        Route::get('domains/{domain}/web', [DomainWebSettingsController::class, 'show'])->name('domains.web.show');
        Route::put('domains/{domain}/error-pages/{status}', [DomainWebSettingsController::class, 'saveErrorPage'])->whereIn('status', ['400', '401', '403', '404', '500', '503'])->name('domains.error-pages.update');
        Route::delete('domains/{domain}/error-pages/{status}', [DomainWebSettingsController::class, 'removeErrorPage'])->whereIn('status', ['400', '401', '403', '404', '500', '503'])->name('domains.error-pages.destroy');
        Route::post('domains/{domain}/protected-directories', [DomainWebSettingsController::class, 'protectDirectory'])->name('domains.protected-directories.store');
        Route::scopeBindings()->group(function () {
            Route::delete('domains/{domain}/protected-directories/{protectedDirectory}', [DomainWebSettingsController::class, 'unprotectDirectory'])->name('domains.protected-directories.destroy');
            Route::post('domains/{domain}/protected-directories/{protectedDirectory}/logins', [DomainWebSettingsController::class, 'addLogin'])->name('domains.protected-directories.logins.store');
            Route::put('domains/{domain}/protected-directories/{protectedDirectory}/logins/{login}', [DomainWebSettingsController::class, 'changeLoginPassword'])->name('domains.protected-directories.logins.update');
            Route::delete('domains/{domain}/protected-directories/{protectedDirectory}/logins/{login}', [DomainWebSettingsController::class, 'removeLogin'])->name('domains.protected-directories.logins.destroy');
        });
        Route::put('domains/{domain}/hotlink', [DomainWebSettingsController::class, 'updateHotlink'])->name('domains.hotlink.update');
        Route::put('domains/{domain}/mime-types', [DomainWebSettingsController::class, 'updateMimeTypes'])->name('domains.mime-types.update');
        Route::put('domains/{domain}/php-extensions', [DomainWebSettingsController::class, 'updatePhpExtensions'])->name('domains.php-extensions.update');
        Route::put('domains/{domain}/cgi', [DomainWebSettingsController::class, 'updateCgi'])->name('domains.cgi.update');
        Route::put('domains/{domain}/limits', [DomainWebSettingsController::class, 'updateLimits'])->name('domains.limits.update');
        Route::get('databases', [DatabaseController::class, 'index'])->name('databases.index');
        Route::post('databases', [DatabaseController::class, 'store'])->name('databases.store');
        Route::delete('databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
        Route::get('databases/{database}/export', [DatabaseToolsController::class, 'export'])->middleware('throttle:10,1,database-export')->name('databases.export');
        Route::post('databases/{database}/import', [DatabaseToolsController::class, 'import'])->name('databases.import');
        Route::post('databases/{database}/maintenance', [DatabaseToolsController::class, 'maintain'])->middleware('throttle:20,1,database-maintenance')->name('databases.maintenance');
        Route::post('database-remote-hosts', [DatabaseToolsController::class, 'storeRemoteHost'])->name('database-remote-hosts.store');
        Route::delete('database-remote-hosts/{databaseRemoteHost}', [DatabaseToolsController::class, 'destroyRemoteHost'])->name('database-remote-hosts.destroy');
        Route::post('databases/phpmyadmin', PhpMyAdminController::class)->middleware('throttle:10,1')->name('databases.phpmyadmin');
        Route::post('database-users', [DatabaseUserController::class, 'store'])->name('database-users.store');
        Route::put('database-users/{databaseUser}', [DatabaseUserController::class, 'update'])->name('database-users.update');
        Route::delete('database-users/{databaseUser}', [DatabaseUserController::class, 'destroy'])->name('database-users.destroy');
        Route::get('email', [EmailController::class, 'index'])->name('email.index');
        Route::post('email', [EmailController::class, 'store'])->name('email.store');
        Route::put('email/{mailbox}', [EmailController::class, 'update'])->name('email.update');
        Route::delete('email/{mailbox}', [EmailController::class, 'destroy'])->name('email.destroy');
        Route::post('email/{mailbox}/webmail', WebmailController::class)->middleware('throttle:10,1')->name('email.webmail');
        Route::post('email/forwarders', [MailForwarderController::class, 'store'])->name('email.forwarders.store');
        Route::put('email/forwarders/{mailForwarder}', [MailForwarderController::class, 'update'])->name('email.forwarders.update');
        Route::delete('email/forwarders/{mailForwarder}', [MailForwarderController::class, 'destroy'])->name('email.forwarders.destroy');
        Route::put('email/catch-all/{mailDomain}', CatchAllController::class)->name('email.catch-all.update');
        Route::put('email/mode/{domain}', MailModeController::class)->name('email.mode.update');
        Route::post('email/ssl/{mailDomain}', MailSslController::class)->middleware('throttle:6,1')->name('email.ssl.store');
        Route::get('email/dns-check/{mailDomain}', MailDnsCheckController::class)
            ->middleware('throttle:20,1')
            ->name('email.dns-check');
        Route::get('email/{mailbox}/rules', [MailboxRulesController::class, 'show'])->name('email.rules');
        Route::put('email/{mailbox}/autoreply', [MailboxRulesController::class, 'updateAutoreply'])->name('email.autoreply.update');
        Route::put('email/{mailbox}/spam', [MailboxRulesController::class, 'updateSpam'])->name('email.spam.update');
        Route::post('email/{mailbox}/filters', [MailboxRulesController::class, 'storeFilter'])->name('email.filters.store');
        Route::delete('email/filters/{mailFilter}', [MailboxRulesController::class, 'destroyFilter'])->name('email.filters.destroy');
        Route::get('dns', [DnsController::class, 'index'])->name('dns.index');
        Route::post('dns/{dnsZone}/records', [DnsController::class, 'store'])->name('dns.records.store');
        Route::put('dns/records/{dnsRecord}', [DnsController::class, 'update'])->name('dns.records.update');
        Route::delete('dns/records/{dnsRecord}', [DnsController::class, 'destroy'])->name('dns.records.destroy');
        Route::post('dns/{dnsZone}/reset', [DnsController::class, 'reset'])->name('dns.reset');
        Route::get('cron-jobs', [CronJobController::class, 'index'])->name('cron-jobs.index');
        Route::post('cron-jobs', [CronJobController::class, 'store'])->name('cron-jobs.store');
        Route::put('cron-jobs/email', [CronJobController::class, 'updateEmail'])->name('cron-jobs.email.update');
        Route::put('cron-jobs/{cronJob}', [CronJobController::class, 'update'])->name('cron-jobs.update');
        Route::delete('cron-jobs/{cronJob}', [CronJobController::class, 'destroy'])->name('cron-jobs.destroy');
        Route::put('sftp-password', [SftpPasswordController::class, 'update'])->middleware('throttle:10,1')->name('sftp-password.update');
        Route::delete('sftp-password', [SftpPasswordController::class, 'destroy'])->name('sftp-password.destroy');
        Route::get('ftp', [FtpAccountController::class, 'index'])->name('ftp.index');
        Route::post('ftp', [FtpAccountController::class, 'store'])->name('ftp.store');
        Route::put('ftp/{ftpAccount}', [FtpAccountController::class, 'update'])->name('ftp.update');
        Route::delete('ftp/{ftpAccount}', [FtpAccountController::class, 'destroy'])->name('ftp.destroy');

        Route::prefix('files')->name('files.')->controller(FileManagerController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('edit', 'edit')->name('edit');
            Route::put('content', 'save')->name('save');
            Route::post('create', 'create')->name('create');
            Route::post('rename', 'rename')->name('rename');
            Route::post('transfer', 'transfer')->name('transfer');
            Route::post('delete', 'delete')->name('delete');
            Route::post('permissions', 'permissions')->name('permissions');
            Route::post('compress', 'compress')->name('compress');
            Route::post('extract', 'extract')->name('extract');
            Route::get('search', 'search')->middleware('throttle:60,1')->name('search');
            Route::post('size', 'size')->middleware('throttle:60,1')->name('size');
            Route::post('upload', 'upload')->middleware('throttle:600,1')->name('upload');
            Route::get('download', 'download')->name('download');
            Route::get('preview', 'preview')->name('preview');
            Route::get('trash', 'trash')->name('trash');
            Route::post('trash/restore', 'restore')->name('restore');
            Route::post('trash/purge', 'purge')->name('purge');
        });
        Route::post('subdomains', [SubdomainController::class, 'store'])->name('subdomains.store');
        Route::post('pointers', [PointerController::class, 'store'])->name('pointers.store');
        Route::get('redirects', [UrlRedirectController::class, 'index'])->name('redirects.index');
        Route::post('redirects', [UrlRedirectController::class, 'store'])->name('redirects.store');
        Route::delete('redirects/{urlRedirect}', [UrlRedirectController::class, 'destroy'])->name('redirects.destroy');
    });

    // A reseller's area: the hosting accounts it owns and its own packages,
    // on the same controllers as the administrators', limited to what the
    // reseller owns (see UserPolicy and PackagePolicy).
    Route::middleware('role:reseller')->prefix('reseller')->name('reseller.')->group(function () {
        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/create', [AccountController::class, 'create'])->name('accounts.create');
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::post('accounts/bulk', [AccountController::class, 'bulk'])->name('accounts.bulk');
        Route::post('accounts/{account}/suspend', [AccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('accounts/{account}/unsuspend', [AccountController::class, 'unsuspend'])->name('accounts.unsuspend');
        Route::put('accounts/{account}/package', [AccountController::class, 'updatePackage'])->name('accounts.package.update');
        Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
        Route::post('accounts/{account}/login-as', [ImpersonationController::class, 'store'])
            ->middleware('throttle:30,1,login-as')
            ->name('accounts.login-as');
        Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
        Route::get('packages/create', [PackageController::class, 'create'])->name('packages.create');
        Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
        Route::get('packages/{package}/edit', [PackageController::class, 'edit'])->name('packages.edit');
        Route::put('packages/{package}', [PackageController::class, 'update'])->name('packages.update');
        Route::delete('packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
        Route::get('statistics', [StatisticsController::class, 'index'])->name('statistics.index');
    });

    // Back to one's own account after "login as".
    Route::delete('impersonation', [ImpersonationController::class, 'destroy'])->name('impersonation.destroy');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/create', [AccountController::class, 'create'])->name('accounts.create');
        Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::post('accounts/bulk', [AccountController::class, 'bulk'])->name('accounts.bulk');
        Route::post('accounts/{account}/suspend', [AccountController::class, 'suspend'])->name('accounts.suspend');
        Route::post('accounts/{account}/unsuspend', [AccountController::class, 'unsuspend'])->name('accounts.unsuspend');
        Route::put('accounts/{account}/package', [AccountController::class, 'updatePackage'])->name('accounts.package.update');
        Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
        Route::put('accounts/{account}/reseller-package', [AccountController::class, 'updateResellerPackage'])->name('accounts.reseller-package.update');
        Route::put('accounts/{account}/owner', [AccountController::class, 'transfer'])->name('accounts.owner.update');
        Route::post('accounts/{account}/login-as', [ImpersonationController::class, 'store'])
            ->middleware('throttle:30,1,login-as')
            ->name('accounts.login-as');
        Route::get('reseller-packages', [ResellerPackageController::class, 'index'])->name('reseller-packages.index');
        Route::get('reseller-packages/create', [ResellerPackageController::class, 'create'])->name('reseller-packages.create');
        Route::post('reseller-packages', [ResellerPackageController::class, 'store'])->name('reseller-packages.store');
        Route::get('reseller-packages/{resellerPackage}/edit', [ResellerPackageController::class, 'edit'])->name('reseller-packages.edit');
        Route::put('reseller-packages/{resellerPackage}', [ResellerPackageController::class, 'update'])->name('reseller-packages.update');
        Route::delete('reseller-packages/{resellerPackage}', [ResellerPackageController::class, 'destroy'])->name('reseller-packages.destroy');
        Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
        Route::get('packages/create', [PackageController::class, 'create'])->name('packages.create');
        Route::post('packages', [PackageController::class, 'store'])->name('packages.store');
        Route::get('packages/{package}/edit', [PackageController::class, 'edit'])->name('packages.edit');
        Route::put('packages/{package}', [PackageController::class, 'update'])->name('packages.update');
        Route::delete('packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
        Route::get('statistics', [StatisticsController::class, 'index'])->name('statistics.index');
        Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('services', [ServiceController::class, 'index'])->name('services.index');
        Route::get('dns', [AdminDnsController::class, 'index'])->name('dns.index');
        Route::get('dns/zones/{dnsZone}', [AdminDnsController::class, 'show'])->name('dns.zones.show');
        Route::post('dns/zones/{dnsZone}/records', [DnsController::class, 'store'])->name('dns.records.store');
        Route::put('dns/records/{dnsRecord}', [DnsController::class, 'update'])->name('dns.records.update');
        Route::delete('dns/records/{dnsRecord}', [DnsController::class, 'destroy'])->name('dns.records.destroy');
        Route::post('dns/zones/{dnsZone}/reset', [DnsController::class, 'reset'])->name('dns.reset');
        Route::post('dns/template', [AdminDnsController::class, 'storeTemplate'])->name('dns.template.store');
        Route::post('dns/template/reset', [AdminDnsController::class, 'resetTemplate'])->name('dns.template.reset');
        Route::put('dns/template/{dnsTemplateRecord}', [AdminDnsController::class, 'updateTemplate'])->name('dns.template.update');
        Route::delete('dns/template/{dnsTemplateRecord}', [AdminDnsController::class, 'destroyTemplate'])->name('dns.template.destroy');
        Route::get('mail-queue', [MailQueueController::class, 'index'])->name('mail-queue.index');
        Route::post('mail-queue/retry', [MailQueueController::class, 'retry'])
            ->middleware('throttle:30,1,mail-queue')
            ->name('mail-queue.retry');
        Route::delete('mail-queue', [MailQueueController::class, 'destroy'])
            ->middleware('throttle:30,1,mail-queue')
            ->name('mail-queue.destroy');
        Route::get('server-settings', [ServerSettingController::class, 'index'])->name('server-settings.index');
        Route::put('server-settings/hostname', [ServerSettingController::class, 'updateHostname'])
            ->middleware('throttle:5,1,server-hostname')
            ->name('server-settings.hostname');
        Route::post('server-settings/port', [ServerSettingController::class, 'tryPort'])
            ->middleware('throttle:5,1,server-port')
            ->name('server-settings.port');
        Route::post('server-settings/port/confirm', [ServerSettingController::class, 'confirmPort'])
            ->middleware('throttle:5,1,server-port-confirm')
            ->name('server-settings.port.confirm');
        Route::delete('server-settings/port', [ServerSettingController::class, 'cancelPort'])
            ->middleware('throttle:10,1,server-port-cancel')
            ->name('server-settings.port.cancel');
        Route::put('server-settings/timezone', [ServerSettingController::class, 'updateTimezone'])
            ->middleware('throttle:10,1,server-timezone')
            ->name('server-settings.timezone');
        Route::post('server-settings/hostname-certificate', [ServerSettingController::class, 'issueHostnameCertificate'])
            ->middleware('throttle:5,1,server-certificate')
            ->name('server-settings.hostname-certificate');
        Route::put('server-settings/database-remote-access', [ServerSettingController::class, 'updateDatabaseRemoteAccess'])
            ->middleware('throttle:5,1,server-database-remote')
            ->name('server-settings.database-remote-access');
        Route::put('server-settings/php-uploads', [ServerSettingController::class, 'updatePhpUploads'])
            ->middleware('throttle:10,1,server-uploads')
            ->name('server-settings.php-uploads');
        Route::put('server-settings/bandwidth', [ServerSettingController::class, 'updateBandwidth'])
            ->middleware('throttle:10,1,server-bandwidth')
            ->name('server-settings.bandwidth');
        Route::put('server-settings/mail-limits', [ServerSettingController::class, 'updateMailSendLimits'])
            ->middleware('throttle:10,1,server-mail-limits')
            ->name('server-settings.mail-limits');
        Route::put('server-settings/nameservers', [ServerSettingController::class, 'updateNameservers'])
            ->middleware('throttle:10,1,server-nameservers')
            ->name('server-settings.nameservers');
        Route::post('services/rebuild', [ServiceController::class, 'rebuild'])
            ->middleware('throttle:6,1')
            ->name('services.rebuild');
        Route::post('services/{unit}/{action}', [ServiceController::class, 'act'])
            ->where('unit', '[a-z0-9][a-z0-9@._-]{0,99}\.service')
            ->whereIn('action', ['start', 'stop', 'restart', 'reload'])
            ->middleware('throttle:30,1')
            ->name('services.act');
    });
});

require __DIR__.'/settings.php';
