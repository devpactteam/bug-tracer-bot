<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily morning reminder (07:00): re-send every open/in_progress ticket to its
// assignee on the assignee bot. If the schedule is missed for any reason the
// reminder is re-triggered the next time a process connects (see the poll
// commands), guarded by a once-per-day marker.
Schedule::command('tickets:resend-pending')->dailyAt('07:00');
