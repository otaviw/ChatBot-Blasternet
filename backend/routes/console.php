<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use App\Console\Commands\BackupDatabase;
use App\Console\Commands\CloseInactiveConversations;
use App\Console\Commands\CompletePassedAppointments;
use App\Console\Commands\PurgeExpiredData;
use App\Jobs\AlertUnattendedConversationsJob;
use App\Jobs\SendAppointmentReminderJob;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(CloseInactiveConversations::class)->hourly();
Schedule::command(CompletePassedAppointments::class)->everyFifteenMinutes();
Schedule::command(PurgeExpiredData::class)->dailyAt('02:00');
Schedule::command(BackupDatabase::class)->dailyAt('03:00');
Schedule::job(new SendAppointmentReminderJob)->hourly();
Schedule::job(new AlertUnattendedConversationsJob)->hourly();
