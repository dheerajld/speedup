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

        // 1️⃣ Notify employees for tasks expiring soon
        $notificationCount = $this->sendExpiringSoonNotifications($fcm, $now, $oneHourFromNow);
        $this->info("✅ Sent {$notificationCount} expiring task notification(s).");

        // 2️⃣ Mark overdue tasks as expired (employee-wise)
        $expiredCount = $this->expireOverdueTasks($now, $fcm);
        $this->info("🕒 Marked {$expiredCount} employee-task(s) as expired.");
    }

    /**
     * 🔔 Notify employees for tasks expiring within 1 hour
     */
    private function sendExpiringSoonNotifications(FcmNotificationService $fcm, $now, $oneHourFromNow): int
    {
        $tasks = Task::with(['employees' => function ($q) {
                $q->wherePivot('status', 'pending');
            }])
            ->whereBetween('deadline', [$now, $oneHourFromNow])
            ->get();

        $count = 0;

        foreach ($tasks as $task) {
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
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * 🕒 Expire overdue employee-tasks and update task status accordingly
     */
    private function expireOverdueTasks($now, FcmNotificationService $fcm): int
    {
        $tasks = Task::with(['employees' => function ($q) {
                $q->whereIn('task_assignments.status', ['pending', 'requested', 'completed']);
            }])
            ->where('deadline', '<', $now)
            ->get();

        $expiredAssignments = 0;

        foreach ($tasks as $task) {
            // 1️⃣ Expire only pending/requested employees
            $expiredEmployees = DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->whereIn('status', ['pending', 'requested'])
                ->pluck('employee_id');

            if ($expiredEmployees->count() > 0) {
                DB::table('task_assignments')
                    ->where('task_id', $task->id)
                    ->whereIn('status', ['pending', 'requested'])
                    ->update(['status' => 'expired']);

                // 2️⃣ Send FCM notification to those employees
                foreach ($expiredEmployees as $employeeId) {
                    $employee = DB::table('employees')->where('id', $employeeId)->first();
                    if ($employee && $employee->device_token) {
                        $fcm->sendAndSave(
                            'Task Expired',
                            "Your task '{$task->name}' has expired.",
                            'task_expired',
                            $employee->id,
                            'employee',
                            $employee->device_token,
                            ['task_id' => $task->id]
                        );
                    }
                }

                $expiredAssignments += $expiredEmployees->count();
            }

            // 3️⃣ Count statuses for this task
            $completedCount = DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->where('status', 'completed')
                ->count();

            $expiredCount = DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->where('status', 'expired')
                ->count();

            $pendingCount = DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->whereNotIn('status', ['completed', 'expired'])
                ->count();

            // 4️⃣ Update task status logically
            if ($completedCount > 0) {
                // ✅ At least one employee completed → task stays completed
                $task->update([
                    'status' => 'completed',
                    'expired_count' => $expiredCount,
                ]);
            } elseif ($pendingCount === 0 && $completedCount === 0) {
                // ❌ No pending or completed → all expired
                if (!in_array($task->status, ['completed', 'expired'])) {
                    $task->update([
                        'status' => 'expired',
                        'expired_count' => DB::raw('expired_count + 1'),
                    ]);
                }
            }
        }

        return $expiredAssignments;
    }
}
