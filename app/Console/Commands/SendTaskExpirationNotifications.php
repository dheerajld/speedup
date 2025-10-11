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

        // 1️⃣ Send reminders for tasks expiring in the next 1 hour
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
        // ✅ Get tasks whose deadline is within the next hour and not expired
        $tasks = Task::with(['employees' => function ($q) {
                $q->wherePivot('status', 'pending');
            }])
            ->whereBetween('deadline', [$now, $oneHourFromNow])
            ->whereNotIn('status', ['completed', 'expired'])
            ->get();

        $count = 0;

        foreach ($tasks as $task) {
            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm->sendAndSave(
                        'Task Expiring Soon',
                        "Your task '{$task->name}' is expiring soon (Deadline: " .
                        Carbon::parse($task->deadline)->format('d M Y h:i A') . "). Please complete it on time.",
                        'task_expiring_soon',
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
        // ✅ Include tasks whose deadline is equal to or before now
        $tasks = Task::with(['employees' => function ($q) {
                $q->whereIn('task_assignments.status', ['pending', 'requested', 'completed']);
            }])
            ->where('deadline', '<=', $now)
            ->get();

        $expiredAssignments = 0;

        foreach ($tasks as $task) {
            // 1️⃣ Find employees whose task is still pending/requested
            $expiredEmployees = DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->whereIn('status', ['pending', 'requested'])
                ->pluck('employee_id');

            if ($expiredEmployees->count() > 0) {
                DB::table('task_assignments')
                    ->where('task_id', $task->id)
                    ->whereIn('status', ['pending', 'requested'])
                    ->update(['status' => 'expired']);

                // 2️⃣ Send FCM "expired" notification to those employees
                foreach ($expiredEmployees as $employeeId) {
                    $employee = DB::table('employees')->where('id', $employeeId)->first();
                    if ($employee && $employee->device_token) {
                        $fcm->sendAndSave(
                            'Task Expired',
                            "Your task '{$task->name}' has expired (Deadline: " .
                            Carbon::parse($task->deadline)->format('d M Y h:i A') . ").",
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

            // 3️⃣ Recalculate overall status for this task
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

            // 4️⃣ Update main task status
            if ($completedCount > 0) {
                $task->update([
                    'status' => 'completed',
                    'expired_count' => $expiredCount,
                ]);
            } elseif ($pendingCount === 0 && $completedCount === 0) {
                if (!in_array($task->status, ['completed', 'expired'])) {
                    $task->update([
                        'status' => 'expired',
                        'expired_count' => $expiredCount,
                    ]);
                }
            }
        }

        return $expiredAssignments;
    }
}
