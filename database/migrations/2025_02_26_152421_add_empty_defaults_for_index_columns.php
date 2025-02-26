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
        // 1. Remove duplicate rows
        DB::table('channels')
            ->whereIn('id', DB::table('channels')
                ->select('id', 'title', 'name', 'group_internal', 'playlist_id')
                ->groupBy('title', 'name', 'group_internal', 'playlist_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()->pluck('id'))
            ->delete();

        // 2. Set empty defaults
        DB::table('channels')
            ->where('group_internal', null)->update(['group_internal' => '']);

        // 3. Update the column to allow empty defaults
        Schema::table('channels', function (Blueprint $table) {
            $table->string('group_internal')->default('')->change();
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
