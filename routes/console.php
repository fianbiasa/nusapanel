<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('nusapanel:collect-usage')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('nusapanel:close-phpmyadmin-sessions')->everyFiveMinutes()->withoutOverlapping();
