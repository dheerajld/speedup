<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FcmNotificationService;

class ResetDailyTasks extends Command
{
    protected $signature = 'tasks:reset-daily';
    protected $description = 'Reset daily recurring tasks';

    public function handle()
    {
        $fcm = new FcmNotificationService();
        $resetCount = 0;

        // ✅ Only reset tasks that are daily, not pending, and deadline expired or due today
        $tasks = Task::where('type', 'daily')
            ->whereDate('deadline', '<=', Carbon::now())
            // ->where('status', '!=', 'pending')
            ->with(['employees'])
            ->get();

        foreach ($tasks as $task) {
            // set deadline to tomorrow end of day
            $task->deadline = Carbon::tomorrow()->setTime(19, 0, 0);
            $task->status = 'pending';
            $task->save();

            // reset all task assignments
            DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->update(['status' => 'pending']);

            // send notifications to employees
            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm->sendAndSave(
                        'Daily Task Reset',
                        "Your daily task '{$task->name}' has been reset. New deadline: " 
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

        $this->info("✅ Reset {$resetCount} daily task(s)");
    }
}
