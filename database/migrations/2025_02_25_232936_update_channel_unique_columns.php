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
        // 1. drop unique index
        Schema::table('channels', function (Blueprint $table) {
            $table->dropUnique(['name', 'group_internal', 'playlist_id', 'user_id']);
        });

        // 2. make sure title set
        DB::statement("UPDATE `channels` SET `title` = `name` where `title` = ''");

        // 3. Remove duplicate rows
        $dupes = DB::table('channels')
            ->select('title', 'name', 'group_internal', 'playlist_id')
            ->groupBy('title', 'name', 'group_internal', 'playlist_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        DB::table('channels')
            ->whereIn('id', $dupes->pluck('id'))
            ->delete();

        // 4. re-add unique index with new column
        Schema::table('channels', function (Blueprint $table) {
            $table->unique(['title', 'name', 'group_internal', 'playlist_id']);
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
