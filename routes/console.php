<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| D-STORE scheduled maintenance
|--------------------------------------------------------------------------
|
| The long-running `dstore:monitor` and `dstore:sync:daemon` are the primary
| workers. These entries are the safety net for a node that only has a
| scheduler (for example a single shared-hosting style deployment), and they
| run the same idempotent code paths.
|
*/

Schedule::command('dstore:monitor --once')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('dstore:sync:run')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('dstore:backup')
    ->dailyAt('02:10')
    ->withoutOverlapping();

Schedule::command('dstore:gc:tombstones')
    ->dailyAt('03:10')
    ->withoutOverlapping();
