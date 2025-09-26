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

        $notificationCount = $this->sendExpiringSoonNotifications($fcm, $now, $oneHourFromNow);
        $this->info("✅ Sent {$notificationCount} expiring task notification(s).");

        $expiredCount = $this->expireOverdueTasks($now);
        $this->info("🕓 Marked {$expiredCount} employee-task(s) as expired.");
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
     * 🕓 Expire overdue tasks (employee-wise and globally if needed)
     */
    private function expireOverdueTasks($now): int
    {
        $tasks = Task::with(['employees' => function ($q) {
                $q->whereIn('task_assignments.status', ['pending', 'requested']);
            }])
            ->where('deadline', '<', $now)
            ->get();

        $expiredAssignments = 0;

        foreach ($tasks as $task) {
            if ($task->employees->isNotEmpty()) {
                // expire all employee assignments
                DB::table('task_assignments')
                    ->where('task_id', $task->id)
                    ->whereIn('status', ['pending', 'requested'])
                    ->update(['status' => 'expired']);

                $expiredAssignments += $task->employees->count();
            }

            // check if any active assignments remain
            $activeCount = DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->whereNotIn('status', ['expired', 'completed'])
                ->count();

            if ($activeCount === 0 && $task->status !== 'expired') {
                $task->update([
                    'status' => 'expired',
                    'expired_count' => DB::raw('expired_count + 1'), // safe increment
                ]);
            }
        }

        return $expiredAssignments;
    }
}
