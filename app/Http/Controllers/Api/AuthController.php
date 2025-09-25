<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:employees',
            'contact_number' => 'required|string|max:20',
            'designation' => 'required|string|max:100',
            'employee_id' => 'required|string|max:50|unique:employees',
            'username' => 'required|string|max:50|unique:employees',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,employee,super_admin',
            'image' => 'nullable|image|max:2048'
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('employee-images', 'public');
        }

        $employee = Employee::create([
            'name' => $request->name,
            'email' => $request->email,
            'contact_number' => $request->contact_number,
            'designation' => $request->designation,
            'employee_id' => $request->employee_id,
            'username' => $request->username,
            'password' => Hash::make($request->password),
            'image_path' => $imagePath,
            'role' => $request->role
        ]);

        $token = $employee->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Employee registered successfully',
            'data' => [
                'employee' => $employee,
                'token' => $token
            ]
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string'
        ]);

        $employee = Employee::where('username', $request->username)
            ->orWhere('email', $request->username)
            ->first();

        if (!$employee || !Hash::check($request->password, $employee->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.']
            ]);
        }

        $token = $employee->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Logged in successfully',
            'data' => [
                'employee' => $employee,
                'token' => $token,
                'role' => $employee->role
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully'
        ]);
    }

public function profile(Request $request)
{
    $employee = $request->user();

    // Hide unwanted fields
    $employee->makeHidden(['status', 'assigned_by']);

    return response()->json([
        'status' => 'success',
        'data' => [
            'employee' => $employee
        ]
    ]);
}

    
   public function deleteEmployee(Employee $employee)
{
    // Optional: Prevent self-deletion or admin deletion
    if (auth()->id() === $employee->id) {
        return response()->json([
            'status' => 'error',
            'message' => 'You cannot delete your own account'
        ], 403);
    }

    $employee->delete();

    return response()->json([
        'status' => 'success',
        'message' => 'Employee deleted successfully'
    ]);
}


}
