<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\EmployeeLocation;

class TruncateEmployeeLocations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'locations:truncate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all records from the employee_locations table';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Use delete() instead of TRUNCATE to avoid FK constraint issues across database drivers
        $deleted = EmployeeLocation::query()->delete();

        $this->info("Employee locations cleared. Rows affected: {$deleted}");
        return self::SUCCESS;
    }
}
