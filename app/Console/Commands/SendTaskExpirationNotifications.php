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

        // 🔔 1. Notify employees with tasks expiring within 1 hour
        $tasksToNotify = Task::with(['employees' => function ($q) {
                $q->wherePivot('status', 'pending');
            }])
            ->whereBetween('deadline', [$now, $oneHourFromNow])
            ->get();

        foreach ($tasksToNotify as $task) {
            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm->sendAndSave(
                        'Task Expiring Soon',
                        "Your task '{$task->name}' is expiring soon (Deadline: " .
                        Carbon::parse($task->deadline)->format('d M Y h:i A') . ")",
                        'task_expiring',
                        $employee->id,
                        'employee',
                        $employee->device_token,
                        ['task_id' => $task->id]
                    );
                    $notificationCount++;
                }
            }
        }

        $this->info("✅ Sent {$notificationCount} expiring task notifications.");

        // 🕓 2. Expire overdue employee-task assignments
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

            // If no active employees left → expire task globally
            $activeCount = DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->whereNotIn('status', ['expired', 'completed'])
                ->count();

            if ($activeCount === 0 && $task->status !== 'expired') {
                $task->update([
                    'status' => 'expired',
                    'expired_count' => $task->expired_count + 1,
                ]);
            }
        }

        $this->info("🕓 Marked {$expiredCount} employee-task(s) as expired.");
    }
}
