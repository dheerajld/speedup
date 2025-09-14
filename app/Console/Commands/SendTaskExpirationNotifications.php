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
    protected $description = 'Send notifications for expiring tasks and update employee-wise expired task statuses';

    public function handle()
    {
        $now = Carbon::now();
        $oneHourFromNow = $now->copy()->addHour();

        $fcm = new FcmNotificationService();
        $notificationCount = 0;

        // 🔔 1. Notify employees with tasks expiring within 1 hour (pivot.status = pending)
        $tasksToNotify = Task::with(['employees' => function ($q) {
                $q->wherePivot('status', 'pending');
            }])
            ->where('deadline', '<=', $oneHourFromNow)
            ->where('deadline', '>', $now)
            ->get();

        foreach ($tasksToNotify as $task) {
            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm->sendAndSave(
                        'Task Expiring Soon',
                        "Your task '{$task->name}' is expiring soon (Deadline: " .
                        Carbon::parse($task->deadline)->format('d M Y h:i A') . ")",
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

        // ✅ 2. Expire employee-wise tasks where deadline already passed & pivot.status = pending/requested
        $expiredTasks = Task::with(['employees' => function ($q) {
                $q->whereIn('task_assignments.status', ['pending', 'requested']);
            }])
            ->where('deadline', '<', $now)
            ->get();

        $expiredCount = 0;

        foreach ($expiredTasks as $task) {
            foreach ($task->employees as $employee) {
                DB::table('task_assignments')
                    ->where('task_id', $task->id)
                    ->where('employee_id', $employee->id)
                    ->update(['status' => 'expired']);
                $expiredCount++;
            }

            // Optional: If all employees expired, mark the task expired
            $activeCount = DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->whereNotIn('status', ['expired', 'completed'])
                ->count();

            if ($activeCount === 0) {
                $task->update([
                    'status' => 'expired',
                    'expired_count' => $task->expired_count + 1,
                ]);
            }
        }

        $this->info("🕓 Updated {$expiredCount} employee-task assignment(s) as expired.");
    }
}
