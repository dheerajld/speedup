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
        // Only consider tasks that are actually past their deadline
        $tasks = Task::with(['employees' => function ($q) {
                $q->whereIn('task_assignments.status', ['pending', 'requested']);
            }])
            ->where('deadline', '<=', $now)
            ->whereNotIn('status', ['completed', 'expired'])
            ->get();

        $expiredAssignments = 0;

        foreach ($tasks as $task) {
            // Skip if task has no employees to expire
            if ($task->employees->isEmpty()) {
                continue;
            }

            // Get the employee IDs that need to be expired
            $employeeIds = $task->employees->pluck('id');

            // Only update tasks that are actually past their deadline
            $expiredAssignmentsToUpdate = DB::table('task_assignments')
                ->join('tasks', 'task_assignments.task_id', '=', 'tasks.id')
                ->where('tasks.deadline', '<=', $now)
                ->where('task_assignments.task_id', $task->id)
                ->whereIn('task_assignments.employee_id', $employeeIds)
                ->whereIn('task_assignments.status', ['pending', 'requested'])
                ->select('task_assignments.*')
                ->get();

            if ($expiredAssignmentsToUpdate->isNotEmpty()) {
                // Update the status to expired for these assignments
                $updated = DB::table('task_assignments')
                    ->whereIn('id', $expiredAssignmentsToUpdate->pluck('id'))
                    ->update(['status' => 'expired']);

                // Send notifications for the expired tasks
                foreach ($expiredAssignmentsToUpdate as $assignment) {
                    $employee = DB::table('employees')->find($assignment->employee_id);
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

                $expiredAssignments += $updated;

                // Only increment expired_count if this is a new expiration
                if ($updated > 0) {
                    $task->increment('expired_count');
                }

                // If all assignments are now expired, update the task status
                $pendingOrRequestedCount = DB::table('task_assignments')
                    ->where('task_id', $task->id)
                    ->whereIn('status', ['pending', 'requested'])
                    ->count();

                if ($pendingOrRequestedCount === 0) {
                    $task->update(['status' => 'expired']);
                } else {
                    // If some assignments are still pending/requested, ensure task status is not expired
                    $task->update(['status' => 'pending']);
                }
            }
        }

        return $expiredAssignments;
    }
}
