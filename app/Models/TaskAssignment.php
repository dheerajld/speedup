<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Employee;
use App\Models\Task;

class TaskAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'employee_id',
        'assigned_by',
    ];

    /**
     * Task this assignment belongs to
     */
    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Employee who is assigned
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Employee who assigned the task
     */
    public function assignedBy()
    {
        return $this->belongsTo(Employee::class, 'assigned_by');
    }
}
