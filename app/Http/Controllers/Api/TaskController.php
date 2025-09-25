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
use Illuminate\Support\Facades\DB;

use App\Services\FcmNotificationService;

class TaskController extends Controller
{
  public function adminDashboard()
{
    $stats = [
        // Total distinct tasks
        'total_tasks'      => Task::count(),
        // Status counts from pivot (employee-specific)
        'pending_tasks'    => \DB::table('task_assignments')->where('status', 'pending')->count(),
        'completed_tasks'  => \DB::table('task_assignments')->where('status', 'completed')->count(),
        'expired_tasks'    => \DB::table('task_assignments')->where('status', 'expired')->count(),
        'requested_tasks'  => \DB::table('task_assignments')->where('status', 'requested')->count(),

        // Total employees
        'total_employees'  => Employee::where('role', 'employee')->count(),
    ];

    return response()->json([
        'status' => 'success',
        'data'   => ['statistics' => $stats]
    ]);
}


public function employeeDashboard(Request $request)
{
    $employee = $request->user();

    // etch tasks assigned to this employee (with pivot status)
    $tasks = $employee->tasks()->withPivot('status')->get();

    // tats based on employee’s pivot status & type
    $stats = [
        'once_tasks'      => $tasks->where('type', 'once')->count(),
        'daily_tasks'     => $tasks->where('type', 'daily')->count(),
        'weekly_tasks'    => $tasks->where('type', 'weekly')->count(),
        'monthly_tasks'   => $tasks->where('type', 'monthly')->count(),
        'yearly_tasks'    => $tasks->where('type', 'yearly')->count(),
        'requested_tasks' => Task::whereHas('employees', function ($q) use ($employee) {
                                $q->where('task_assignments.assigned_by', $employee->id);
                            })->count(),
        'completed_tasks' => $tasks->filter(fn($t) => $t->pivot->status === 'completed')->count(),
        'pending_tasks'   => $tasks->filter(fn($t) => $t->pivot->status === 'pending')->count(),
        'expired_tasks'   => $tasks->filter(fn($t) => $t->pivot->status === 'expired')->count(),
    ];

    // Assigned tasks (specific to logged-in employee)
    $taskList = $employee->tasks()
        ->with('employees:id,name') // only fetch necessary fields
        ->orderByDesc('created_at')
        ->get()
        ->map(function ($task) use ($employee) {
            return [
                'task_no'       => $task->id,
                'name'          => $task->name,
                'description'   => $task->description,
                'created_by' => $task->created_by,
                'assigned_date' => $task->created_at->format('Y-m-d H:i:s'),
                'deadline'      => $task->deadline ? $task->deadline->format('Y-m-d H:i:s') : null,
                'status'        => $task->employees->where('id', $employee->id)->first()?->pivot->status, // employee-specific
                'type'          => $task->type,
                'assigned_to'   => $task->employees->pluck('name'),
            ];
        });

    // Tasks created/assigned by this employee
    $createdTasks = Task::whereHas('employees', function ($q) use ($employee) {
            $q->where('task_assignments.assigned_by', $employee->id);
        })
        ->with([
            'employees' => function ($q) {
                $q->select(
                    'employees.id',
                    'employees.name',
                    'employees.email',
                    'employees.contact_number',
                    'employees.designation',
                    'employees.employee_id',
                    'employees.username',
                    'employees.image_path',
                    'employees.role',
                    'employees.device_token',
                    'employees.created_at',
                    'employees.updated_at'
                );
            }
        ])
        ->orderByDesc('created_at')
        ->get();

    return response()->json([
        'status' => 'success',
        'data'   => [
            'statistics'          => $stats,
            'tasks'               => $taskList,
            'created_and_assigned'=> $createdTasks,
        ]
    ]);
}




public function employeeTasks(Request $request)
{
    $employee = $request->user();

    $tasks = $employee->tasks()
        ->with('employees:id,name') // load employees without email
        ->when($request->type, function ($query, $type) {
            return $query->where('type', $type);
        })
        ->when($request->status, function ($query, $status) use ($employee) {
            // Filter by pivot status for current employee
            return $query->whereHas('employees', function ($q) use ($employee, $status) {
                $q->where('employee_id', $employee->id)
                  ->where('task_assignments.status', $status);
            });
        })
        ->orderBy('deadline')
        ->get()
        ->map(function ($task) use ($employee) {
            return [
                'task_no'      => $task->id,
                'name'         => $task->name,
                'description'  => $task->description,
                'created_by'   => $task->created_by,
                'assigned_date'=> $task->created_at->format('Y-m-d H:i:s'),
                'deadline'     => $task->deadline ? $task->deadline->format('Y-m-d H:i:s') : null,
                'status'       => $task->employees->where('id', $employee->id)->first()?->pivot->status,
                'type'         => $task->type,
                'assigned_to'  => $task->employees->map(function ($emp) {
                    return [
                        'id'          => $emp->id,
                        'name'        => $emp->name,
                        'status'      => $emp->pivot->status,
                        'assigned_by' => $emp->pivot->assigned_by,
                    ];
                }),
            ];
        });

    return response()->json([
        'status' => 'success',
        'data' => ['tasks' => $tasks]
    ]);
}



public function updateTaskStatus(Request $request, Task $task)
{
    $request->validate([
        'status' => 'required|in:completed,expired,requested,pending',
        'photo_base64' => 'nullable|array',
        'photo_base64.*' => 'string',
    ]);

    $employee = $request->user();

    // ✅ Ensure employee is assigned
    if (!$task->employees->contains($employee->id)) {
        return response()->json([
            'status' => 'error',
            'message' => 'You are not authorized to update this task status.',
        ], 403);
    }

    // ✅ Update only this employee’s pivot status
    $task->employees()->updateExistingPivot($employee->id, [
        'status' => $request->status,
    ]);

    // ✅ Check if all employees have completed → then mark global task as completed
    $allCompleted = $task->employees()->wherePivot('status', '!=', 'completed')->count() === 0;
    if ($allCompleted) {
        $task->update(['status' => 'completed']);
    }

    // ✅ Save base64 photos (global for the task)
    if ($request->filled('photo_base64')) {
        $existingBase64 = $task->photos ?? [];
        $mergedBase64 = array_merge($existingBase64, $request->photo_base64);
        $task->update(['photos' => $mergedBase64]);
    }

    // ✅ Notifications when employee completes
    if ($request->status === 'completed') {
        $fcm = new FcmNotificationService();

        // Notify Admin
         $admins = Employee::whereIn('role', ['admin', 'super_admin'])->get();
    foreach ($admins as $admin) {
        if ($admin->device_token) {
            $fcm->sendAndSave(
                'Task Completed',
                'Task #' . $task->id . ' has been completed by ' . $employee->name,
                'task_completed',
                $admin->id,
                $admin->role,
                $admin->device_token,
                ['task_id' => $task->id]
            );
        }
    }

        // Notify Task Creator
        $creatorId = $task->employees()
            ->wherePivot('employee_id', $employee->id)
            ->pluck('task_assignments.assigned_by')
            ->first();

        if ($creatorId) {
            $creator = Employee::find($creatorId);
            if ($creator && $creator->device_token) {
                $fcm->sendAndSave(
                    'Task Completed by Employee',
                    $employee->name . ' has completed the task: ' . $task->name,
                    'task_completed',
                    $creator->id,
                    'employee',
                    $creator->device_token,
                    ['task_id' => $task->id]
                );
            }
        }
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Task status updated successfully.',
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
            'created_by'  => auth()->id(),
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

    $tasks = $query->with('employees') // eager load employees
        ->orderBy('created_at', 'desc')
        ->get();

    // Update expired tasks & increment counter
    foreach ($tasks as $task) {
        if ($task->status === 'pending' && $task->deadline && $task->deadline->lt(Carbon::now())) {

            foreach ($task->employees as $employee) {
                if ($employee->device_token) {
                    $fcm = new FcmNotificationService();
                    $fcm->sendAndSave(
                        'Task Expired',
                        "Your task '{$task->name}' expired (Deadline: " . $task->deadline->format('d M Y h:i A') . ")",
                        'task_expired',
                        $employee->id,
                        'employee',
                        $employee->device_token,
                        ['task_id' => $task->id]
                    );
                }

                // ✅ Update pivot status
              $task->employees()->updateExistingPivot($employee->id, [
                    'status' => 'expired',
                ]);
            }

            $task->update([
                'status' => 'expired',
                'expired_count' => $task->expired_count + 1
            ]);
        }
    }

    // ✅ Format response
    $formattedTasks = $tasks->map(function ($task) {
        return [
            'task_id'     => $task->id,
            'name'        => $task->name,
            'description' => $task->description,
            'type'        => $task->type,
            'status'      => $task->status,
            'created_by' => $task->created_by,
            'deadline'    => $task->deadline ? $task->deadline->format('Y-m-d H:i:s') : null,
            'expired_count' => $task->expired_count,
            'employees'   => $task->employees->map(function ($emp) {
                return [
                    'id'     => $emp->id,
                    'name'   => $emp->name,
                    'email'  => $emp->email,
                    'status' => $emp->pivot->status,   // 👈 pivot status
                    'assigned_by' => $emp->pivot->assigned_by,
                ];
            })
        ];
    });

    return response()->json([
        'status' => 'success',
        'data'   => ['tasks' => $formattedTasks]
    ]);
}
    

public function store(Request $request)
{
    $request->validate([
        'name'         => 'required|string|max:255',
        'description'  => 'required|string',
        'type'         => 'required|in:daily,weekly,monthly,yearly,once',
        'deadline'     => 'required|date|after:now',
        'employee_ids' => 'required|array',
        'employee_ids.*' => 'exists:employees,id'
    ]);

    // Create task
    $task = Task::create([
        'name'        => $request->name,
        'description' => $request->description,
        'type'        => $request->type,
        'deadline'    => $request->deadline,
        'created_by'  => auth()->id(),
        'status'      => 'pending'
    ]);

    $assignedById = $request->user()->id ?? null;

    // Attach employees with assigned_by & default pivot status
    $attachData = [];
    foreach ($request->employee_ids as $employeeId) {
        $attachData[$employeeId] = [
            'assigned_by' => $assignedById,
            'status'      => 'pending'   // default pivot status
        ];
    }
    $task->employees()->attach($attachData);

    // Send push notifications
    $fcm = new FcmNotificationService();
    $employees = Employee::whereIn('id', $request->employee_ids)->get();

    foreach ($employees as $employee) {
        if ($employee->device_token) {
            $assignerName = $request->user() ? $request->user()->name : 'Someone';
            $body = $assignerName . " has assigned you the task '{$task->name}'";

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
        'status'  => 'success',
        'message' => 'Task created successfully',
        'data'    => [
            'task' => $task->load('employees') // load employees with pivot data
        ]
    ], 201);
}


  public function show(Task $task)
{
    $task->load('employees');

    $formattedTask = [
        'task_id'     => $task->id,
        'name'        => $task->name,
        'description' => $task->description,
        'type'        => $task->type,
        'created_by' => $task->created_by,
        'photos' => $task->photos,
        'status'      => $task->status,  // overall task status
        'deadline'    => $task->deadline ? $task->deadline->format('Y-m-d H:i:s') : null,
        'expired_count' => $task->expired_count,
        'employees'   => $task->employees->map(function ($emp) {
            return [
                'id'            => $emp->id,
                'name'          => $emp->name,
                'email'         => $emp->email,
                'status' => $emp->pivot->status,  
                'assigned_by'   => $emp->pivot->assigned_by,
                'assigned_at'   => $emp->pivot->created_at ? $emp->pivot->created_at->format('Y-m-d H:i:s') : null,
            ];
        }),
    ];

    return response()->json([
        'status' => 'success',
        'data'   => ['task' => $formattedTask]
    ]);
}

    
    
public function update(Request $request, Task $task)
{
    $request->validate([
        'name'         => 'nullable|string|max:255',
        'description'  => 'nullable|string',
        'type'         => 'nullable|string|in:daily,weekly,monthly,yearly,once',
        'deadline'     => 'nullable|date|after_or_equal:now',
        'status'       => 'nullable|in:pending,completed,expired,requested',
        'employee_ids' => 'nullable|array',
        'employee_ids.*' => 'exists:employees,id',
    ]);

    // ✅ Update main task fields
    $task->update([
        'name'        => $request->name ?? $task->name,
        'description' => $request->description ?? $task->description,
        'type'        => $request->type ?? $task->type,
        'deadline'    => $request->deadline ?? $task->deadline,
    ]);

    // ✅ Sync employees if provided
    if ($request->filled('employee_ids')) {
        $syncData = [];
        foreach ($request->employee_ids as $empId) {
            $syncData[$empId] = [
                'assigned_by' => $request->user()->id,
                'status' => 'pending', // reset pivot status
            ];
        }
        $task->employees()->sync($syncData);
    }

    // ✅ Update pivot status for all employees if status passed
    if ($request->filled('status')) {
        foreach ($task->employees as $emp) {
            $task->employees()->updateExistingPivot($emp->id, [
                'status' => $request->status,
            ]);
        }

        // Update global task status if all completed
        if ($request->status === 'completed') {
            $allCompleted = $task->employees()->wherePivot('status', '!=', 'completed')->count() === 0;
            if ($allCompleted) {
                $task->update(['status' => 'completed']);
            }
        } elseif ($request->status === 'expired') {
            $task->update(['status' => 'expired']);
        } else {
            $task->update(['status' => 'pending']);
        }
    }

    // ✅ Notify all assigned employees
    $fcm = new FcmNotificationService();
    foreach ($task->employees as $emp) {
        $title = 'Task Updated';
        $message = "The task '{$task->name}' assigned to you has been updated. Current status: " .
                   ($emp->pivot->status ?? $task->status) . ".";


        // Send FCM push notification
        if ($emp->device_token) {
            $fcm->sendAndSave(
                $title,
                $message,
                'task_update',
                $emp->id,
                'employee',
                $emp->device_token,
                ['task_id' => $task->id]
            );
        }
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
        'deadline' => 'required|date|after:now',
    ]);

    $previousStatus = $task->status;

    // ✅ Update deadline
    $task->update([
        'deadline' => $request->deadline,
    ]);

    // ✅ Update pivot statuses for all employees
    foreach ($task->employees as $emp) {
        $task->employees()->updateExistingPivot($emp->id, [
            'status' => $request->status,
        ]);
    }

    // ✅ If admin sets global status → update main task status
    $task->update(['status' => $request->status]);

    // ✅ Increment expired_count only when moving out of expired
    if ($previousStatus !== 'expired' && $request->status === 'expired') {
        $task->increment('expired_count');
    }

    // ✅ Initialize FCM service
    $fcm = new FcmNotificationService();

    // ✅ Notify employees about status change
    foreach ($task->employees as $emp) {
        if ($emp->device_token) {
            $fcm->sendAndSave(
                'Task Status Updated',
                "The task '{$task->name}' status has been changed to {$request->status} by Admin.",
                'task_status_updated',
                $emp->id,
                'employee',
                $emp->device_token,
                ['task_id' => $task->id]
            );
        }
    }

    return response()->json([
        'status' => 'success',
        'message' => 'Task status and deadline updated successfully by admin',
        'data' => [
            'task' => $task->load('employees'),
        ],
    ]);
}







  public function statistics()
{
    // Count by type
    $typeCounts = Task::select('type', DB::raw('COUNT(*) as total'))
        ->groupBy('type')
        ->pluck('total', 'type');

    // Count by status
    $statusCounts = Task::select('status', DB::raw('COUNT(*) as total'))
        ->groupBy('status')
        ->pluck('total', 'status');

    // Merge into final stats
    $stats = [
        'once'     => $typeCounts['once'] ?? 0,
        'daily'    => $typeCounts['daily'] ?? 0,
        'weekly'   => $typeCounts['weekly'] ?? 0,
        'monthly'  => $typeCounts['monthly'] ?? 0,
        'yearly'   => $typeCounts['yearly'] ?? 0,
        'pending'  => $statusCounts['pending'] ?? 0,
        'completed'=> $statusCounts['completed'] ?? 0,
        'expired'  => $statusCounts['expired'] ?? 0,
        'requested'=> $statusCounts['requested'] ?? 0,
    ];

    return response()->json([
        'status' => 'success',
        'data'   => ['statistics' => $stats]
    ]);
}
    
public function allEmployees()
{
    $employees = Employee::with(['latestLocation']) // eager load latest location
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
        'from_date'   => 'required|date',
        'to_date'     => 'required|date|after_or_equal:from_date',
        'employee_id' => 'required|exists:employees,id',
    ]);

    $from       = Carbon::parse($request->from_date)->startOfDay();
    $to         = Carbon::parse($request->to_date)->endOfDay();
    $employeeId = $request->employee_id;

    $now        = now();
    $endOfMonth = $now->copy()->endOfMonth();

    // Base query → only tasks assigned to this employee within the date range
    $taskQuery = Task::whereHas('employees', function ($query) use ($employeeId) {
        $query->where('task_assignments.employee_id', $employeeId);
    })->whereBetween('created_at', [$from, $to]);

    // ✅ Count statuses from pivot table (task_assignments)
    $pending   = (clone $taskQuery)->whereHas('employees', fn($q) => $q->where('task_assignments.status', 'pending'))->count();
    $completed = (clone $taskQuery)->whereHas('employees', fn($q) => $q->where('task_assignments.status', 'completed'))->count();
    $expired   = (clone $taskQuery)->whereHas('employees', fn($q) => $q->where('task_assignments.status', 'expired'))->count();
    $requested = (clone $taskQuery)->whereHas('employees', fn($q) => $q->where('task_assignments.status', 'requested'))->count();

    // Reset completed count if to_date >= end of month
    if ($to->gte($endOfMonth)) {
        $completed = 0;
    }

    // Get task details with assigned employees and pivot data
    $allTasks = $taskQuery->with(['employees' => function ($q) use ($employeeId) {
        $q->where('task_assignments.employee_id', $employeeId)
          ->select('employees.id', 'employees.name');
    }])->get();

    return response()->json([
        'status' => 'success',
        'data' => [
            'from_date'       => $from->toDateString(),
            'to_date'         => $to->toDateString(),
            'employee_id'     => $employeeId,
            'pending_tasks'   => $pending,
            'completed_tasks' => $completed,
            'expired_tasks'   => $expired,
            'requested_tasks' => $requested,
            'tasks'           => $allTasks,
        ],
    ]);
}

public function taskReportEmployee(Request $request)
{
    $request->validate([
        'from_date' => 'required|date',
        'to_date'   => 'required|date|after_or_equal:from_date',
    ]);

    $employee   = $request->user();
    $employeeId = $employee->id;

    $from       = Carbon::parse($request->from_date)->startOfDay();
    $to         = Carbon::parse($request->to_date)->endOfDay();
    $now        = now();
    $endOfMonth = $now->copy()->endOfMonth();

    // Base query → only tasks assigned to this employee within date range
    $taskQuery = Task::whereHas('employees', function ($query) use ($employeeId) {
        $query->where('task_assignments.employee_id', $employeeId);
    })->whereBetween('created_at', [$from, $to]);

    // ✅ Count statuses from pivot (task_assignments)
    $pending   = (clone $taskQuery)->whereHas('employees', fn($q) => $q->where('task_assignments.status', 'pending'))->count();
    $completed = (clone $taskQuery)->whereHas('employees', fn($q) => $q->where('task_assignments.status', 'completed'))->count();
    $expired   = (clone $taskQuery)->whereHas('employees', fn($q) => $q->where('task_assignments.status', 'expired'))->count();
    $requested = (clone $taskQuery)->whereHas('employees', fn($q) => $q->where('task_assignments.status', 'requested'))->count();

    // Reset completed count at month end
    if ($to->gte($endOfMonth)) {
        $completed = 0;
    }

    // Get detailed tasks with employee pivot info
    $tasks = $taskQuery->with(['employees' => function ($q) use ($employeeId) {
        $q->where('task_assignments.employee_id', $employeeId)
          ->select('employees.id', 'employees.name');
    }])->get();

    return response()->json([
        'status' => 'success',
        'data' => [
            'from_date'       => $from->toDateString(),
            'to_date'         => $to->toDateString(),
            'pending_tasks'   => $pending,
            'completed_tasks' => $completed,
            'expired_tasks'   => $expired,
            'requested_tasks' => $requested,
            'tasks'           => $tasks,
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

public function employeeDeleteTask(Request $request, $id)
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





    
    
}
