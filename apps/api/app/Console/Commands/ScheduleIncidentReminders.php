<?php

namespace App\Console\Commands;

use App\Services\IncidentReminderService;
use Illuminate\Console\Command;

class ScheduleIncidentReminders extends Command
{
    protected $signature = 'status:schedule-reminders';

    protected $description = 'Queue policy-driven reminders for active incidents';

    public function handle(IncidentReminderService $service): int
    {
        $this->info($service->schedule().' incident reminders queued.');

        return self::SUCCESS;
    }
}
