<?php

use App\Models\BandwidthUsage;
use App\Models\DomainBandwidth;
use App\Models\ResourceUsage;
use App\Models\UsageSnapshot;
use App\Models\WebmailSession;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('rayxo:collect-usage')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('rayxo:collect-bandwidth')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('rayxo:close-phpmyadmin-sessions')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('rayxo:sync-send-limits')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('rayxo:check-alerts')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('rayxo:collect-resources')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('rayxo:clean-transfers')->hourly()->withoutOverlapping();
Schedule::command('rayxo:measure-domain-disk')->hourly()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [WebmailSession::class, BandwidthUsage::class, DomainBandwidth::class, UsageSnapshot::class, ResourceUsage::class]])->hourly()->withoutOverlapping();
Schedule::command('rayxo:recount-ftp-quotas')->dailyAt('03:40')->withoutOverlapping();
Schedule::command('rayxo:sync-mail-ssl')->dailyAt('04:20')->withoutOverlapping();
Schedule::command('rayxo:sync-ssl-expiry')->dailyAt('04:30')->withoutOverlapping();
