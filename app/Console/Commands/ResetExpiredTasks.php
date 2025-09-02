<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use Carbon\Carbon;
use App\Services\FcmNotificationService;

class ResetExpiredTasks extends Command
{
    protected $signature = 'tasks:reset-expired';
    protected $description = 'Reset expired recurring tasks and update deadlines based on type';

    public function handle()
    {
        // Fetch expired tasks of all recurring types
        $tasks = Task::whereIn('type', ['daily','weekly','monthly','yearly'])
            ->where('status', 'expired')
            ->get();

        $fcm = new FcmNotificationService();

        foreach ($tasks as $task) {
            // Update deadline based on task type
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
                    // Keep current deadline if type is unknown
                    break;
            }

            // Reset status to pending
            $task->status = 'pending';
            $task->save();

            // Notify assigned employees
            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm->sendAndSave(
                        'Task Reset',
                        "Your task '{$task->name}' has been reset to pending. New deadline: " . ($task->deadline ? $task->deadline->format('Y-m-d H:i') : 'N/A'),
                        'task_reset',
                        $employee->id,
                        'employee',
                        $employee->device_token,
                        ['task_id' => $task->id]
                    );
                }
            }
        }

        $this->info("Reset {$tasks->count()} expired tasks with updated deadlines.");
    }
}
