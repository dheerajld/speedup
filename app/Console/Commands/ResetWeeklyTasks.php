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
        $resetCount = 0;

        // ✅ Only reset weekly tasks that are due this week or earlier
        $tasks = Task::where('type', 'weekly')
            ->whereDate('deadline', '<=', Carbon::now())
            ->with(['employees'])
            ->get();

        foreach ($tasks as $task) {
            // set new deadline → next week same weekday end of day
            $task->deadline = Carbon::now()->copy()->addWeek()->endOfDay();
            $task->status = 'pending';
            $task->save();

            // reset all task assignments
            DB::table('task_assignments')
                ->where('task_id', $task->id)
                ->update(['status' => 'pending']);

            // send push notification to employees
            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm->sendAndSave(
                        'Weekly Task Reset',
                        "Your weekly task '{$task->name}' has been reset. New deadline: " 
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

        $this->info("✅ Reset {$resetCount} weekly task(s)");
    }
}
