<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Models\Task;

class SendTaskExpirationNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-task-expiration-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
  public function handle()
{
    $tasks = Task::with('employees')
        ->where('status', 'pending')
        ->where('deadline', '<', now()->subHour(-1))
        ->get();

    foreach ($tasks as $task) {
        foreach ($task->employees as $employee) {
            if ($employee->device_token) {
                $fcm = new FcmNotificationService();
                $fcm->sendAndSave(
                    'Task Expiring Today',
                    "Your task '{$task->name}' is expiring today (Deadline: " . Carbon::parse($task->deadline)->format('d M Y h:i A') . ")",
                    'task_expired',
                    $employee->id,
                    'employee',
                    $employee->device_token,
                    ['task_id' => $task->id]
                );
            }
        }

        $task->update([
            'status' => 'expired',
            'expired_count' => $task->expired_count + 1,
        ]);
    }

    $this->info("Notifications sent for " . $tasks->count() . " expired tasks.");
}

}
