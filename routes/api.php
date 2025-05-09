<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Common routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);

    // Admin routes
    Route::middleware('admin')->group(function () {
        Route::get('/admin/dashboard', [TaskController::class, 'adminDashboard']);
        Route::get('/admin/tasks', [TaskController::class, 'index']);
        Route::post('/admin/tasks', [TaskController::class, 'store']);
        Route::get('/admin/tasks/employee-report', [TaskController::class, 'taskReportAdmin']);
        Route::get('/admin/tasks/{task}', [TaskController::class, 'show']);
        Route::patch('/admin/tasks/{task}', [TaskController::class, 'update']);
        Route::delete('/admin/tasks/{task}', [TaskController::class, 'deleteTask']);
        Route::get('/admin/task-statistics', [TaskController::class, 'statistics']);
        Route::get('/admin/employees', [TaskController::class, 'allEmployees']);
        Route::get('/admin/task-report', [TaskController::class, 'downloadTaskReport']);
        Route::patch('/admin/tasks/{task}/status', [TaskController::class, 'updateStatusAdmin']);
 

        

        
    });

    // Employee routes
    Route::middleware('employee')->group(function () {
        Route::get('/employee/dashboard', [TaskController::class, 'employeeDashboard']);
        Route::get('/employee/tasks', [TaskController::class, 'employeeTasks']);
        Route::patch('/employee/tasks/{task}/status', [TaskController::class, 'updateTaskStatus']);
        Route::post('/employee/tasks/request', [TaskController::class, 'requestTask']);
        Route::get('/employee/employees', [TaskController::class, 'allEmployees']);
        Route::post('/employee/request-reassign-task', [TaskController::class, 'requestReassignTask']);
        Route::post('/employee/{employee}/track-location', [TaskController::class, 'trackLocation']);
    });
});
