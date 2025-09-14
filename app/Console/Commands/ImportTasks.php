<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Models\TaskAssignment;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportTasks extends Command
{
    protected $signature = 'tasks:import {file}';
    protected $description = 'Import tasks and task assignments from XLSX or CSV file';

    public function handle()
    {
        $filePath = $this->argument('file');

        if (!file_exists($filePath)) {
            $this->error("❌ File not found: $filePath");
            return 1;
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $rows = [];

        if (in_array($extension, ['xls', 'xlsx'])) {
            // ✅ Use PhpSpreadsheet for Excel
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);

            // First row is header
            $header = array_map('strtolower', $rows[1]);
            unset($rows[1]);
        } elseif ($extension === 'csv') {
            // ✅ Handle CSV
            $rows = array_map('str_getcsv', file($filePath));
            $header = array_shift($rows);
        } else {
            $this->error("❌ Unsupported file format: .$extension");
            return 1;
        }

        $count = 0;

        foreach ($rows as $row) {
            if (empty(array_filter($row))) continue; // skip empty rows

            $data = array_combine($header, array_values($row));

            // Insert into tasks
            $task = Task::create([
                'name'        => $data['name'],
                'description' => $data['description'],
                'type'        => $data['type'],
                'deadline'    => Carbon::parse($data['deadline']),
                'status'      => $data['status'],
                'created_by'  => $data['created_by'],
            ]);

            // Insert into task_assignments
            $employeeIds = explode(',', $data['employee_ids']);
            foreach ($employeeIds as $employeeId) {
                TaskAssignment::create([
                    'task_id'     => $task->id,
                    'employee_id' => trim($employeeId),
                    'assigned_by' => $data['created_by'],
                    'status'      => 'pending',
                ]);
            }

            $count++;
        }

        $this->info("✅ Successfully imported {$count} tasks with assignments.");
        return 0;
    }
}
