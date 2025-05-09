<?php

namespace App\Exports;

use App\Models\Task;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;

class TaskReportExport implements FromCollection, WithMapping, WithHeadings
{
    protected $from;
    protected $to;
    protected $employeeId;

    public function __construct($from, $to, $employeeId)
    {
        $this->from = Carbon::parse($from)->startOfDay();
        $this->to = Carbon::parse($to)->endOfDay();
        $this->employeeId = $employeeId;
    }

    public function collection()
    {
        return Task::whereHas('employees', function ($q) {
                $q->where('task_assignments.employee_id', $this->employeeId); // Qualified to avoid ambiguity
            })
            ->with(['employees.latestLocation'])
            ->whereBetween('created_at', [$this->from, $this->to])
            ->get();
    }

    public function map($task): array
    {
        $employee = $task->employees->first(); // Assuming 1 employee per task
        $location = $employee?->latestLocation;

        return [
            $task->id,
            $task->name,
            $task->description,
            $task->type,
            $task->status,
            optional($task->deadline)->toDateTimeString(),
            $employee?->name ?? 'N/A',
            $location?->address ?? 'N/A',
            $location?->latitude ?? '',
            $location?->longitude ?? '',
            $task->created_at->toDateTimeString(),
        ];
    }

    public function headings(): array
    {
        return [
            'Task ID',
            'Task Name',
            'Description',
            'Type',
            'Status',
            'Deadline',
            'Employee Name',
            'Location Address',
            'Latitude',
            'Longitude',
            'Created At',
        ];
    }
}
