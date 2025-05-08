<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Employee;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

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
        'daily_tasks' => $tasks->where('type', 'daily')->count(),
        'weekly_tasks' => $tasks->where('type', 'weekly')->count(),
        'monthly_tasks' => $tasks->where('type', 'monthly')->count(),
        'yearly_tasks' => $tasks->where('type', 'yearly')->count(),
        'once_tasks' => $tasks->where('type', 'once')->count(),
        'requested_tasks' => 0 // Implement if needed
    ];

    // Separate query for detailed task list (if you want relationships)
    $taskList = $employee->tasks()
        ->with('employees')
        ->orderBy('deadline')
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

        $tasks = $query->with('employees')->get();

        // Update expired tasks
        foreach ($tasks as $task) {
            if ($task->status === 'pending' && $task->deadline < Carbon::now()) {
                $task->update(['status' => 'expired']);
            }
        }

        return response()->json([
            'status' => 'success',
            'data' => ['tasks' => $tasks]
        ]);
    }

    public function store(Request $request)
    {
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


    public function statistics()
    {
        $stats = [
            'daily' => Task::where('type', 'daily')->count(),
            'weekly' => Task::where('type', 'weekly')->count(),
            'monthly' => Task::where('type', 'monthly')->count(),
            'yearly' => Task::where('type', 'yearly')->count(),
            'once' => Task::where('type', 'once')->count(),
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
    $employees = Employee::where('role', 'employee')->get();

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
        'type' => 'required|in:daily,weekly,monthly,yearly,once',
        'from_date' => 'nullable|date',
        'to_date' => 'nullable|date|after_or_equal:from_date'
    ]);

    $query = Task::where('type', $request->type)->with('employees');

    if ($request->filled('from_date')) {
        $query->whereDate('created_at', '>=', Carbon::parse($request->from_date));
    }

    if ($request->filled('to_date')) {
        $query->whereDate('created_at', '<=', Carbon::parse($request->to_date));
    }

    $tasks = $query->get();

    $csvHeader = ['Task ID', 'Name', 'Description', 'Deadline', 'Status', 'Assigned Employees'];
    $csvData = [];

    foreach ($tasks as $task) {
        $employeeNames = $task->employees->pluck('name')->join(', ');
        $csvData[] = [
            $task->id,
            $task->name,
            $task->description,
            $task->deadline->format('Y-m-d H:i:s'),
            $task->status,
            $employeeNames
        ];
    }

    $filename = 'task_report_' . $request->type . '_' . now()->format('Ymd_His') . '.csv';

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, $csvHeader);

    foreach ($csvData as $row) {
        fputcsv($handle, $row);
    }

    rewind($handle);
    $content = stream_get_contents($handle);
    fclose($handle);

    return Response::make($content, 200, [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => "attachment; filename=\"$filename\"",
    ]);
}


}
