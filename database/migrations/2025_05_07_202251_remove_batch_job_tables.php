<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Clenup the job tables since they are not used
        DB::table('job_batches')->truncate();
        DB::table('failed_jobs')->truncate();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
