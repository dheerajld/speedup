<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        Employee::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'contact_number' => '1234567890',
            'designation' => 'Administrator',
            'employee_id' => 'ADMIN001',
            'username' => 'admin',
            'password' => Hash::make('admin123'),
            'role' => 'admin'
        ]);

        // Create sample employees
        $employees = [
            [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'contact_number' => '9876543210',
                'designation' => 'Developer',
                'employee_id' => 'EMP001',
                'username' => 'johndoe',
                'password' => Hash::make('password123'),
                'role' => 'employee'
            ],
            [
                'name' => 'Jane Smith',
                'email' => 'jane@example.com',
                'contact_number' => '8765432109',
                'designation' => 'Designer',
                'employee_id' => 'EMP002',
                'username' => 'janesmith',
                'password' => Hash::make('password123'),
                'role' => 'employee'
            ],
            [
                'name' => 'Mike Johnson',
                'email' => 'mike@example.com',
                'contact_number' => '7654321098',
                'designation' => 'Accountant',
                'employee_id' => 'EMP003',
                'username' => 'mikejohnson',
                'password' => Hash::make('password123'),
                'role' => 'employee'
            ],
            [
                'name' => 'Sarah Wilson',
                'email' => 'sarah@example.com',
                'contact_number' => '6543210987',
                'designation' => 'HR',
                'employee_id' => 'EMP004',
                'username' => 'sarahwilson',
                'password' => Hash::make('password123'),
                'role' => 'employee'
            ]
        ];

        foreach ($employees as $employee) {
            Employee::create($employee);
        }
    }
}
