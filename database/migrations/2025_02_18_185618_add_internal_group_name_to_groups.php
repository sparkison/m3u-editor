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
        // 1. creating a new column
        Schema::table('groups', function (Blueprint $table) {
            $table->string('name_internal')->after('name')->nullable();
        });

        // 2. copying the existing column values into new one using Laravel's query builder
        DB::table('groups')->update(['name_internal' => DB::raw('name')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            //
        });
    }
};
