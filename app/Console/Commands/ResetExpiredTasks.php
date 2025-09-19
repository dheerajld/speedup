<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // 👈 Add this
use App\Services\FcmNotificationService;

class ResetExpiredTasks extends Command
{
    protected $signature = 'tasks:reset-all';
    protected $description = 'Reset all recurring tasks (employee-wise and globally) with updated deadlines';

    public function handle()
    {
        $fcm = new FcmNotificationService();
        $resetCount = 0;

        // 🔍 Fetch ALL recurring tasks
        $tasks = Task::whereIn('type', ['daily', 'weekly', 'monthly', 'yearly'])
            ->with('employees')
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

            // ✅ Reset task status globally
            $task->status = 'pending';
            $task->save();

            // ✅ Reset all employees (pivot table)
            DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->update(['status' => 'pending']);

            // 🔔 Notify all employees
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

                    // 📝 Log employee notification
                    Log::info("📲 Task reset notification sent", [
                        'task_id'    => $task->id,
                        'task_name'  => $task->name,
                        'employee_id'=> $employee->id,
                        'deadline'   => $task->deadline->format('Y-m-d H:i')
                    ]);
                }
            }

            // 📝 Log task reset
            Log::info("✅ Task reset", [
                'task_id'   => $task->id,
                'task_name' => $task->name,
                'type'      => $task->type,
                'deadline'  => $task->deadline->format('Y-m-d H:i')
            ]);

            $resetCount++;
        }

        // Final log + console output
        Log::info("🔄 Total recurring tasks reset", ['count' => $resetCount]);
        $this->info("✅ Reset {$resetCount} recurring task(s) (all employees + globally).");
    }
}
