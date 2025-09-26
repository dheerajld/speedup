<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\FcmNotificationService;

class ResetYearlyTasks extends Command
{
    protected $signature = 'tasks:reset-yearly';
    protected $description = 'Reset yearly recurring tasks';

    public function handle()
    {
        $fcm = new FcmNotificationService();
        $resetCount = 0;

        // ✅ Only reset yearly tasks that are due today or earlier
        $tasks = Task::where('type', 'yearly')
            ->whereDate('deadline', '<=', Carbon::now())
            ->where('status', '!=', 'pending')
            ->with(['employees'])
            ->get();

        foreach ($tasks as $task) {
            // ✅ Add a year safely (handles leap years)
            $newDeadline = Carbon::parse($task->deadline)
                ->addYearNoOverflow()
                ->endOfDay();

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
                        'Yearly Task Reset',
                        "Your yearly task '{$task->name}' has been reset. New deadline: " 
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

        $this->info("✅ Reset {$resetCount} yearly task(s)");
    }
}
