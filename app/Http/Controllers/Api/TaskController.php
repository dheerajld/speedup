<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Employee;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use App\Models\EmployeeLocation;
use App\Exports\TaskReportExport;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\Notification;

use App\Services\FcmNotificationService;

class TaskController extends Controller
{
    public function adminDashboard()
    {
        $stats = [
            'total_tasks' => Task::count(),
            'pending_tasks' => Task::where('status', 'pending')->count(),
            'completed_tasks' => Task::where('status', 'completed')->count(),
            'expired_tasks' => Task::where('status', 'expired')->count(),
            'requested_tasks' => Task::where('status', 'requested')->count(),
            'total_employees' => Employee::where('role', 'employee')->count()
        ];

        return response()->json([
            'status' => 'success',
            'data' => ['statistics' => $stats]
        ]);
    }

   public function employeeDashboard(Request $request)
{
    $employee = $request->user();
    
    // FETCH tasks ONCE as a COLLECTION
    $tasks = $employee->tasks()->get();

    $stats = [
        'once_tasks' => $tasks->where('type', 'once')->count(),
        'daily_tasks' => $tasks->where('type', 'daily')->count(),
        'weekly_tasks' => $tasks->where('type', 'weekly')->count(),
        'monthly_tasks' => $tasks->where('type', 'monthly')->count(),
        'yearly_tasks' => $tasks->where('type', 'yearly')->count(),
        'requested_tasks' => 0 // Implement if needed
    ];

    // Separate query for detailed task list (if you want relationships)
    $taskList = $employee->tasks()
        ->with('employees')
        ->orderByDesc('created_at')
        ->get()
        ->map(function ($task) {
            return [
                'task_no' => $task->id,
                'name' => $task->name,
                'description' => $task->description,
                'assigned_date' => $task->created_at->format('Y-m-d H:i:s'),
                'deadline' => $task->deadline->format('Y-m-d H:i:s'),
                'status' => $task->status,
                'type' => $task->type
            ];
        });

    return response()->json([
        'status' => 'success',
        'data' => [
            'statistics' => $stats,
            'tasks' => $taskList
        ]
    ]);
}


    public function employeeTasks(Request $request)
    {
        $employee = $request->user();
        $tasks = $employee->tasks()
            ->with('employees')
            ->when($request->type, function ($query, $type) {
                return $query->where('type', $type);
            })
            ->when($request->status, function ($query, $status) {
                return $query->where('status', $status);
            })
            ->orderBy('deadline')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => ['tasks' => $tasks]
        ]);
    }

public function updateTaskStatus(Request $request, Task $task)
{
    $request->validate([
        'status' => 'required|in:completed,expired,requested,pending',
        'employee_ids' => 'nullable|array',
        'employee_ids.*' => 'exists:employees,id',
        'photo_base64' => 'nullable|array',
        'photo_base64.*' => 'string', // Each base64 image as a string
    ]);

    $employee = $request->user();

    if (!$task->employees->contains($employee->id)) {
        return response()->json([
            'status' => 'error',
            'message' => 'You are not authorized to update this task status.',
        ], 403);
    }

    $task->update([
        'status' => $request->status,
    ]);

    if ($request->filled('employee_ids')) {
        $task->employees()->sync($request->employee_ids);
    }

    // Save base64 strings directly
    if ($request->filled('photo_base64')) {
        $existingBase64 = $task->photo_base64 ?? []; // Assuming it's casted to array
        $mergedBase64 = array_merge($existingBase64, $request->photo_base64);

        $task->update([
            'photos' => $mergedBase64,
        ]);
    }

    // Send push notification to admin when task is completed
    if ($request->status === 'completed') {
        $admin = Employee::where('role', 'admin')->first();
        if ($admin && $admin->device_token) {
            $fcm = new FcmNotificationService();
            $fcm->sendAndSave(
                'Task Completed',
                'Task #' . $task->id . ' has been completed by ' . $request->user()->name,
                'task_completed',
                $admin->id,
                'admin',
                $admin->device_token,
                ['task_id' => $task->id]
            );
        }
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Task updated successfully.',
        'data' => [
            'task' => $task->load('employees'),
        ],
    ]);
}






    public function requestTask(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|in:daily,weekly,monthly,yearly,once',
            'deadline' => 'required|date|after:now'
        ]);

        $task = Task::create([
            'name' => $request->name,
            'description' => $request->description,
            'type' => $request->type,
            'deadline' => $request->deadline,
            'status' => 'pending'
        ]);

        $task->employees()->attach($request->user()->id);

        // Send push notification to admin when task is created
        $admin = Employee::where('role', 'admin')->first();
        if ($admin && $admin->device_token) {
            $fcm = new FcmNotificationService();
            $fcm->sendAndSave(
                'New Task Request from ' . $request->user()->name,
                $request->user()->name . ' has requested a new task: ' . $task->name . ' (Deadline: ' . Carbon::parse($task->deadline)->format('d M Y h:i A') . ')',
                'task_created',
                $admin->id,
                'admin',
                $admin->device_token,
                ['task_id' => $task->id]
            );
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Task requested successfully',
            'data' => ['task' => $task->load('employees')]
        ], 201);
    }
    public function index(Request $request)
    {
        $query = Task::query();
    
        if ($request->type) {
            $query->where('type', $request->type);
        }
    
        if ($request->status) {
            $query->where('status', $request->status);
        }
    
        $tasks = $query->with('employees')
               ->orderBy('created_at', 'desc')
               ->get();
    
        // Update expired tasks and increment counter
        foreach ($tasks as $task) {
            if ($task->status === 'pending' && $task->deadline < Carbon::now()->subHour(-1)) {
                // Task expired before 1 hour, send notification to employees
                foreach ($task->employees as $employee) {
                    if ($employee->device_token) {
                        $fcm = new FcmNotificationService();
                        $fcm->sendAndSave(
                            'Task Expiring Today',
                            "Your task '{$task->name}' is expiring today (Deadline: " . Carbon::parse(
                                $task->deadline
                            )->format('d M Y h:i A') . ")",
                            'task_expired',
                            $employee->id,
                            'employee',
                            $employee->device_token,
                            ['task_id' => $task->id]
                        );
                    }
                }
                $task->update([
                    'status' => 'expired',
                    'expired_count' => $task->expired_count + 1
                ]);
            }
        }
    
        return response()->json([
            'status' => 'success',
            'data' => ['tasks' => $tasks]
        ]);
    }
    

    public function store(Request $request)
    {
        // After creating a task, send push notification to employees
        // (FCM logic will be added after task creation below)

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|in:daily,weekly,monthly,yearly,once',
            'deadline' => 'required|date|after:now',
            'employee_ids' => 'required|array',
            'employee_ids.*' => 'exists:employees,id'
        ]);

        $task = Task::create([
            'name' => $request->name,
            'description' => $request->description,
            'type' => $request->type,
            'deadline' => $request->deadline,
            'status' => 'pending'
        ]);

        $task->employees()->attach($request->employee_ids);

        // Send push notification to assigned employees
        $fcm = new FcmNotificationService();
        $employees = Employee::whereIn('id', $request->employee_ids)->get();
        foreach ($employees as $employee) {
            if ($employee->device_token) {
                $assignerName = $request->user() ? $request->user()->name : 'Someone';
                $body = $assignerName . "sir has assigned you the task '{$task->name}'";
                $fcm->sendAndSave(
                    'New Task Assigned',
                    $body,
                    'task_created',
                    $employee->id,
                        'employee',
                    $employee->device_token,
                    ['task_id' => $task->id]
                );
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Task created successfully',
            'data' => ['task' => $task->load('employees')]
        ], 201);
    }

    public function show(Task $task)
    {
        return response()->json([
            'status' => 'success',
            'data' => ['task' => $task->load('employees')]
        ]);
    }

  public function update(Request $request, Task $task)
{
    $request->validate([
        'type' => 'nullable|string|max:255',
        'status' => 'required|in:pending,completed,expired',
        'deadline' => 'nullable|date|after_or_equal:now',
        'employee_ids' => 'nullable|array',
        'employee_ids.*' => 'exists:employees,id',
    ]);

    // Update the task fields
    $task->update([
        'type' => $request->type,
        'status' => $request->status,
        'deadline' => $request->deadline,
    ]);

    // Sync employees if provided
    if ($request->filled('employee_ids')) {
        $task->employees()->sync($request->employee_ids);
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Task updated successfully',
        'data' => [
            'task' => $task->load('employees'),
        ],
    ]);
}

public function updateStatusAdmin(Request $request, Task $task)
{
    $request->validate([
        'status' => 'required|in:pending,completed,expired',
        'deadline' => 'required|date|after:now', // accepts full timestamp
    ]);

    $previousStatus = $task->status;

    $task->update([
        'status' => $request->status,
        'deadline' => $request->deadline,
    ]);

    // Increment expired_count only when status is changed from expired to something else
    if ($previousStatus === 'expired' && $request->status !== 'expired') {
        $task->increment('expired_count');
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Task status and deadline updated successfully',
        'data' => [
            'task' => $task,
        ],
    ]);
}






    public function statistics()
    {
        $stats = [
            'once' => Task::where('type', 'once')->count(),
            'daily' => Task::where('type', 'daily')->count(),
            'weekly' => Task::where('type', 'weekly')->count(),
            'monthly' => Task::where('type', 'monthly')->count(),
            'yearly' => Task::where('type', 'yearly')->count(),
            'pending' => Task::where('status', 'pending')->count(),
            'completed' => Task::where('status', 'completed')->count(),
            'expired' => Task::where('status', 'expired')->count(),
             'requested' => Task::where('status', 'requested')->count(),
        ];

        return response()->json([
            'status' => 'success',
            'data' => ['statistics' => $stats]
        ]);
    }
    
public function allEmployees()
{
    $employees = Employee::where('role', 'employee')
        ->with(['latestLocation']) // eager load latest location
        ->get();

    return response()->json([
        'status' => 'success',
        'data' => ['employees' => $employees]
    ]);
}


public function requestReassignTask(Request $request)
{
    $request->validate([
        'task_id' => 'required|exists:tasks,id',
        'to_employee_ids' => 'required|array|min:1',
        'to_employee_ids.*' => 'exists:employees,id',
    ]);

    $employee = $request->user();
    $task = Task::findOrFail($request->task_id);

    // Find the current assignment entry
    $assignment = $task->employees()->wherePivot('employee_id', $employee->id)->first();

    if (!$assignment) {
        return response()->json([
            'status' => 'error',
            'message' => 'You cannot reassign this task because you are not assigned to it.',
        ], 403);
    }

    // Delete the old pivot record (only this employee for this task)
    $task->employees()->detach($employee->id);

    // Attach the new employee(s) to same task_id (if not already assigned)
        foreach ($request->to_employee_ids as $toEmployeeId) {
            $alreadyAssigned = $task->employees()->wherePivot('employee_id', $toEmployeeId)->exists();
            
            if (!$alreadyAssigned) {
                $task->employees()->attach($toEmployeeId);
            }
        }
        // Send push notification to new employees
        $fcm = new FcmNotificationService();
        $newEmployees = Employee::whereIn('id', $request->to_employee_ids)->get();
        foreach ($newEmployees as $employee) {
            if ($employee->device_token) {
                $fcm->sendAndSave(
                    'Task Reassigned',
                    'You have been reassigned to task: ' . $task->name,
                    'task_reassigned',
                    $employee->id,
                    'employee',
                    $employee->device_token,
                    ['task_id' => $task->id]
                );
            }
        }

    return response()->json([
        'status' => 'success',
        'message' => 'Task reassigned successfully to new employee(s).',
        'data' => [
            'task' => $task->load('employees')
        ]
    ]);
}



public function downloadTaskReport(Request $request)
{
    $request->validate([
        'from_date' => 'required|date',
        'to_date' => 'required|date|after_or_equal:from_date',
        'employee_id' => 'required|exists:employees,id',
    ]);

    // Get employee name
    $employee = Employee::findOrFail($request->employee_id);

    // Generate file name with employee's name
    $fileName = 'task_report_' . $employee->name . '_' . now()->format('Ymd_His') . '.xlsx';

    return Excel::download(
        new TaskReportExport($request->from_date, $request->to_date, $request->employee_id),
        $fileName
    );
}



    public function deleteTask($id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'status' => 'error',
                'message' => 'Task not found'
            ], 404);
        }

        $task->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Task deleted successfully'
        ]);
    }

    public function trackLocation(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
        ]);

        $location = EmployeeLocation::create([
            'employee_id' => $employee->id,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'name' => $validated['name'] ?? null,
            'address' => $validated['address'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $location
        ]);
    }
    public function taskReportAdmin(Request $request)
    {
        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'employee_id' => 'required|exists:employees,id',
        ]);
    
        $from = Carbon::parse($request->from_date)->startOfDay();
        $to = Carbon::parse($request->to_date)->endOfDay();
        $employeeId = $request->employee_id;
    
        $now = now();
        $endOfMonth = $now->copy()->endOfMonth();
    
        // Base query for tasks assigned to the employee in the date range
        $taskQuery = Task::whereHas('employees', function ($query) use ($employeeId) {
            $query->where('task_assignments.employee_id', $employeeId);
        })->whereBetween('created_at', [$from, $to]);
    
        // Clone queries for counts
        $pending = (clone $taskQuery)->where('status', 'pending')->count();
        $completed = (clone $taskQuery)->where('status', 'completed')->count();
        $expired = (clone $taskQuery)->where('status', 'expired')->count();
        $requested = (clone $taskQuery)->where('status', 'requested')->count();
    
        // Reset completed count if to_date is at or after end of month
        if ($to->gte($endOfMonth)) {
            $completed = 0;
        }
    
        // Get all matching task details with assigned employees
        $allTasks = $taskQuery->with('employees:id,name')->get();
    
        return response()->json([
            'status' => 'success',
            'data' => [
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'employee_id' => $employeeId,
                'pending_tasks' => $pending,
                'completed_tasks' => $completed,
                'expired_tasks' => $expired,
                'requested_tasks' => $requested,
                'tasks' => $allTasks,
            ],
        ]);
    }

    public function taskReportEmployee(Request $request)
{
    $request->validate([
        'from_date' => 'required|date',
        'to_date' => 'required|date|after_or_equal:from_date',
    ]);

    $employee = $request->user();
    $employeeId = $employee->id;

    $from = Carbon::parse($request->from_date)->startOfDay();
    $to = Carbon::parse($request->to_date)->endOfDay();
    $now = now();
    $endOfMonth = $now->copy()->endOfMonth();

    // Base query for tasks assigned to the employee in the date range
    $taskQuery = Task::whereHas('employees', function ($query) use ($employeeId) {
        $query->where('task_assignments.employee_id', $employeeId);
    })->whereBetween('created_at', [$from, $to]);

    // Clone queries for status-wise counts
    $pending = (clone $taskQuery)->where('status', 'pending')->count();
    $completed = (clone $taskQuery)->where('status', 'completed')->count();
    $expired = (clone $taskQuery)->where('status', 'expired')->count();
    $requested = (clone $taskQuery)->where('status', 'requested')->count();

    // Reset completed if to_date is end of month or beyond
    if ($to->gte($endOfMonth)) {
        $completed = 0;
    }

    // Get detailed tasks
    $tasks = $taskQuery->with('employees:id,name')->get();

    return response()->json([
        'status' => 'success',
        'data' => [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'pending_tasks' => $pending,
            'completed_tasks' => $completed,
            'expired_tasks' => $expired,
            'requested_tasks' => $requested,
            'tasks' => $tasks,
        ],
    ]);
}

public function employeeNotificationList(Request $request)
{
    $employee = $request->user();

    $notifications = Notification::where('recipient_id', $employee->id)
        ->orderBy('created_at', 'desc')
        ->get();

    $unreadCount = Notification::where('recipient_id', $employee->id)
        ->whereNull('read_at')
        ->count();

    return response()->json([
        'status' => 'success',
        'data' => [
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]
    ]);
}

public function markAllNotificationsAsRead(Request $request)
{
    $userId = $request->user()->id;

    Notification::where('recipient_id', $userId)
        ->whereNull('read_at')
        ->update(['read_at' => now()]);

    return response()->json([
        'status' => 'success',
        'message' => 'All unread notifications marked as read.'
    ]);
}





    
    
}
