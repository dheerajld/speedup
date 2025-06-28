<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // In the migration file
public function up()
{
    Schema::table('task_assignments', function (Blueprint $table) {
        $table->foreignId('assigned_by')->nullable()->after('employee_id')->constrained('employees')->onDelete('set null');
    });
}

public function down()
{
    Schema::table('task_assignments', function (Blueprint $table) {
        $table->dropColumn('assigned_by');
    });
}

};
