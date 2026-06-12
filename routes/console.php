<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('gdpr:cleanup-exports')->daily();

Schedule::command('storage:reconcile-user-usage')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('uploads:gc-sessions')->hourly();

Schedule::command('media:reconcile-fileflux-jobs')->everyFiveMinutes();

Schedule::command('printdeal:sync-products')
    ->dailyAt('04:00')
    ->onOneServer()
    ->withoutOverlapping();
