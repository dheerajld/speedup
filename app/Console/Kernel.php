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
        //  \App\Console\Commands\ResetExpiredTasks::class,
          \App\Console\Commands\ResetDailyTasks::class,
        \App\Console\Commands\ResetWeeklyTasks::class,
        \App\Console\Commands\ResetMonthlyTasks::class,
        \App\Console\Commands\ResetYearlyTasks::class,
        \App\Console\Commands\TruncateEmployeeLocations::class,
    ];
     
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
         $schedule->command('task:send-expiration-notifications')->everyFiveMinutes();
          // Schedule new task reset command daily at midnight
        // $schedule->command('tasks:reset-all')->dailyAt('00:00');
           // 🕓 Recurring task resets by type
        $schedule->command('tasks:reset-daily')->dailyAt('00:00');
        $schedule->command('tasks:reset-weekly')->weeklyOn(0, '00:00'); // Sunday
        $schedule->command('tasks:reset-monthly')->monthlyOn(1, '00:00'); // 1st of month
        $schedule->command('tasks:reset-yearly')->yearlyOn(1, 1, '00:00'); // Jan 1
        
        // Truncate employee locations table daily at 1 AM
        $schedule->command('locations:truncate')->dailyAt('01:00');
        
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
