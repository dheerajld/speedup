<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\FcmNotificationService;

class ResetExpiredTasks extends Command
{
    protected $signature = 'tasks:reset-expired';
    protected $description = 'Reset expired recurring tasks (employee-wise and globally) with updated deadlines';

    public function handle()
    {
        $fcm = new FcmNotificationService();
        $resetCount = 0;

        // 🔍 Fetch recurring tasks with at least one expired employee
        $tasks = Task::whereIn('type', ['daily', 'weekly', 'monthly', 'yearly'])
            ->whereHas('employees', function ($q) {
                $q->where('task_assignments.status', 'expired');
            })
            ->with(['employees' => function ($q) {
                $q->where('task_assignments.status', 'expired');
            }])
            ->get();

        foreach ($tasks as $task) {
            // 🕓 Update deadline based on recurrence type
            switch ($task->type) {
                case 'daily':
                    $task->deadline = Carbon::now()->addDay();
                    break;
                case 'weekly':
                    $task->deadline = Carbon::now()->addWeek();
                    break;
                case 'monthly':
                    $task->deadline = Carbon::now()->addMonth();
                    break;
                case 'yearly':
                    $task->deadline = Carbon::now()->addYear();
                    break;
                default:
                    continue 2; // skip unknown type
            }

            $task->save();

            // ✅ Reset expired employees (pivot)
            DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->where('status', 'expired')
                ->update(['status' => 'pending']);

            // ✅ Check if ALL employees were expired (global reset)
            $totalAssignments = DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->count();

            $expiredAssignments = DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->where('status', 'expired')
                ->count();

            if ($expiredAssignments === $totalAssignments) {
                // All employees were expired → reset task globally
                $task->status = 'pending';
                $task->save();
            }

            // 🔔 Notify employees whose tasks were reset
            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm->sendAndSave(
                        'Task Reset',
                        "Your recurring task '{$task->name}' has been reset to pending. New deadline: " .
                        ($task->deadline ? $task->deadline->format('Y-m-d H:i') : 'N/A'),
                        'task_reset',
                        $employee->id,
                        'employee',
                        $employee->device_token,
                        ['task_id' => $task->id]
                    );
                }
            }

            $resetCount++;
        }

        $this->info("✅ Reset {$resetCount} expired recurring task(s) (employee-wise + globally).");
    }
}
