<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::table('task_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('task_assignments', 'status')) {
                $table->enum('status', ['pending', 'completed', 'expired', 'requested'])
                      ->default('pending')
                      ->after('assigned_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_assignments', function (Blueprint $table) {
            if (Schema::hasColumn('task_assignments', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
