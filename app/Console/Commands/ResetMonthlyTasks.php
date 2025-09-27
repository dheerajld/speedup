<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\FcmNotificationService;

class ResetMonthlyTasks extends Command
{
    protected $signature = 'tasks:reset-monthly';
    protected $description = 'Reset monthly recurring tasks';

    public function handle()
    {
        $fcm = new FcmNotificationService();
        $resetCount = 0;

        // ✅ Only reset monthly tasks that are due today or earlier
        $tasks = Task::where('type', 'monthly')
            ->whereDate('deadline', '<=', Carbon::now())
            ->with(['employees'])
            ->get();

        foreach ($tasks as $task) {
            // ✅ Set new deadline → next month, same day if possible, or last day of month
            $newDeadline = Carbon::parse($task->deadline)->addMonthNoOverflow()->endOfDay();

            $task->deadline = $newDeadline;
            $task->status = 'pending';
            $task->save();

            // reset assignments
            DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->update(['status' => 'pending']);

            // notify employees
            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm->sendAndSave(
                        'Monthly Task Reset',
                        "Your monthly task '{$task->name}' has been reset. New deadline: " 
                            . $task->deadline->format('Y-m-d H:i'),
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

        $this->info("✅ Reset {$resetCount} monthly task(s)");
    }
}
