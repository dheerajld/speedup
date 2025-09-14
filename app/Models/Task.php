<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Employee;

class Task extends Model
{
    use HasFactory;

       protected $fillable = [
        'name',
        'description',
        'type',
        'deadline',
        'status',
        'created_by',
        'photos',    
        'expired_count'
    ];

    protected $casts = [
        'photos'   => 'array',
        'deadline' => 'datetime',
    ];

  public function employees()
    {
        return $this->belongsToMany(Employee::class, 'task_assignments')
            ->withPivot(['status', 'assigned_by', 'created_at', 'updated_at'])
            ->withTimestamps();
    }

 /**
     * Assignments (with assigned_by relation)
     */
    public function assignments()
    {
        return $this->hasMany(TaskAssignment::class);
    }

}
