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
        $tasks = Task::where('type', 'daily')->where('status', '!=', 'pending')->with(['employees'])->get();
        $resetCount = 0;

        foreach ($tasks as $task) {
            $task->deadline = Carbon::parse($task->deadline)->addDay();
            $task->status = 'pending';
            $task->save();

            DB::table('task_assignments')->where('task_id', $task->id)->update(['status' => 'pending']);

            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm->sendAndSave(
                        'Daily Task Reset',
                        "Your daily task '{$task->name}' has been reset. New deadline: " . $task->deadline->format('Y-m-d H:i'),
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
