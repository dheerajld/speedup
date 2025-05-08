<?php

namespace Database\Seeders;

use App\Models\Task;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class TaskSeeder extends Seeder
{
    public function run(): void
    {
        $employees = Employee::where('role', 'employee')->get();

        $tasks = [
            [
                'name' => 'Daily Office Cleaning',
                'description' => 'Clean office spaces including desks, floors, and common areas.',
                'type' => 'daily',
                'deadline' => Carbon::now()->addDays(1),
                'status' => 'pending'
            ],
            [
                'name' => 'Weekly Report Submission',
                'description' => 'Prepare and submit weekly progress report.',
                'type' => 'weekly',
                'deadline' => Carbon::now()->addDays(7),
                'status' => 'pending'
            ],
            [
                'name' => 'Monthly Team Meeting',
                'description' => 'Conduct monthly team meeting and discuss project progress.',
                'type' => 'monthly',
                'deadline' => Carbon::now()->addMonth(),
                'status' => 'pending'
            ],
            [
                'name' => 'Annual Performance Review',
                'description' => 'Complete annual performance reviews for team members.',
                'type' => 'yearly',
                'deadline' => Carbon::now()->addYear(),
                'status' => 'pending'
            ],
            [
                'name' => 'Project Documentation',
                'description' => 'Update project documentation and user manuals.',
                'type' => 'weekly',
                'deadline' => Carbon::now()->addDays(5),
                'status' => 'pending'
            ],
            [
                'name' => 'Code Review',
                'description' => 'Review and provide feedback on team code submissions.',
                'type' => 'daily',
                'deadline' => Carbon::now()->addHours(8),
                'status' => 'pending'
            ],
            [
                'name' => 'Database Backup',
                'description' => 'Perform monthly database backup and maintenance.',
                'type' => 'monthly',
                'deadline' => Carbon::now()->addMonth(),
                'status' => 'pending'
            ],
            [
                'name' => 'Client Meeting',
                'description' => 'Meet with clients to discuss project requirements.',
                'type' => 'weekly',
                'deadline' => Carbon::now()->addDays(3),
                'status' => 'pending'
            ]
        ];

        foreach ($tasks as $taskData) {
            $task = Task::create($taskData);
            
            // Randomly assign 1-3 employees to each task
            $assigneeCount = rand(1, 3);
            $assignees = $employees->random($assigneeCount);
            $task->employees()->attach($assignees->pluck('id'));
        }

        // Create some completed and expired tasks
        $completedTask = Task::create([
            'name' => 'System Update',
            'description' => 'Update system software and security patches.',
            'type' => 'monthly',
            'deadline' => Carbon::now()->subDays(5),
            'status' => 'completed'
        ]);
        $completedTask->employees()->attach($employees->random(2));

        $expiredTask = Task::create([
            'name' => 'Budget Review',
            'description' => 'Review and update department budget.',
            'type' => 'monthly',
            'deadline' => Carbon::now()->subDays(1),
            'status' => 'expired'
        ]);
        $expiredTask->employees()->attach($employees->random(2));
    }
}
