<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\FcmNotificationService;

class ResetWeeklyTasks extends Command
{
    protected $signature = 'tasks:reset-weekly';
    protected $description = 'Reset weekly recurring tasks';

    public function handle()
    {
        $fcm = new FcmNotificationService();
        $tasks = Task::where('type', 'weekly')->where('status', '!=', 'pending')->with(['employees'])->get();
        $resetCount = 0;

        foreach ($tasks as $task) {
            $task->deadline = Carbon::parse($task->deadline)->addWeek();
            $task->status = 'pending';
            $task->save();

            DB::table('task_assignments')->where('task_id', $task->id)->update(['status' => 'pending']);

            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm->sendAndSave(
                        'Weekly Task Reset',
                        "Your weekly task '{$task->name}' has been reset. New deadline: " . $task->deadline->format('Y-m-d H:i'),
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

        $this->info("✅ Reset {$resetCount} weekly task(s)");
    }
}
