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
        if (!Schema::hasColumn('channels', 'group_internal')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->string('group_internal')->after('group')->nullable();
            });
        }

        // 2. copying the existing column values into new one
        DB::statement("UPDATE `channels` SET `group_internal` = `group`");

        // 3. drop unique index
        Schema::table('channels', function (Blueprint $table) {
            $table->dropUnique(['name', 'group', 'playlist_id', 'user_id']);
        });

        // 4. re-add unique index with new column
        Schema::table('channels', function (Blueprint $table) {
            $table->unique(['name', 'group_internal', 'playlist_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            //
        });
    }
};
