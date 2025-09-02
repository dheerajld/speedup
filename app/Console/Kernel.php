<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
     
     protected $commands = [
        \App\Console\Commands\SendTaskExpirationNotifications::class,
         \App\Console\Commands\ResetExpiredTasks::class,
    ];
     
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
         $schedule->command('task:send-expiration-notifications')->everyFiveMinutes();
          // Schedule new task reset command daily at midnight
        $schedule->command('tasks:reset-expired')->dailyAt('00:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
    
}
