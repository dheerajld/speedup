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
        'photos'
    ];

    protected $casts = [
        'deadline' => 'datetime'
    ];

   public function employees()
{
    return $this->belongsToMany(Employee::class, 'task_assignments')
                ->withPivot('assigned_by')
                ->withTimestamps();
}

}
