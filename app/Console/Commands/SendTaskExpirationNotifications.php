<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Services\FcmNotificationService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SendTaskExpirationNotifications extends Command
{
    protected $signature = 'app:send-task-expiration-notifications';

    protected $description = 'Send notifications for expiring tasks and update expired task status';

    public function handle()
    {
        $now = Carbon::now();
        $oneHourFromNow = $now->copy()->addHour();

        $fcm = new FcmNotificationService();
        $notificationCount = 0;

        // 🔔 1. Notify about tasks that are pending and expiring within 1 hour
        $tasksToNotify = Task::with('employees')
            ->where('status', 'pending')
            ->where('deadline', '<=', $oneHourFromNow)
            ->where('deadline', '>', $now)
            ->get();

        foreach ($tasksToNotify as $task) {
            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm->sendAndSave(
                        'Task Expiring Soon',
                        "Your task '{$task->name}' is expiring soon (Deadline: " . Carbon::parse($task->deadline)->format('d M Y h:i A') . ")",
                        'task_expired',
                        $employee->id,
                        'employee',
                        $employee->device_token,
                        ['task_id' => $task->id]
                    );
                    $notificationCount++;
                }
            }
        }

        $this->info("✅ Notifications sent for {$tasksToNotify->count()} task(s) ($notificationCount total messages).");

        // ✅ 2. Mark tasks as 'expired' if deadline passed, status is NOT 'completed' or 'expired'
        $expiredTasks = Task::where('deadline', '<', $now)
            ->whereNotIn('status', ['completed', 'expired'])
            ->get();

        foreach ($expiredTasks as $task) {
            $task->update([
                'status' => 'expired',
                'expired_count' => $task->expired_count + 1,
            ]);
        }

        $this->info("🕓 Updated {$expiredTasks->count()} task(s) as expired.");
    }
}
