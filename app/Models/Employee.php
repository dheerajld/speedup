<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Employee extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'contact_number',
        'designation',
        'employee_id',
        'username',
        'password',
        'image_path',
        'role',
    ];

    public function isAdmin()
    {
        return $this->role === 'admin';
    }

    public function isEmployee()
    {
        return $this->role === 'employee';
    }

    protected $hidden = [
        'password',
    ];

    public function tasks()
    {
        return $this->belongsToMany(Task::class, 'task_assignments');
    }
}
