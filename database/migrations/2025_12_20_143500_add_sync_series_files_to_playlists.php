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
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('auto_sync_series_stream_files')->default(false)
                ->after('auto_fetch_series_metadata')
                ->comment('Automatically sync stream files for series on playlist sync');
        });

        // Migrate existing data: if auto_fetch_series_metadata was enabled, 
        // assume they also want stream files synced (to maintain existing behavior)
        DB::table('playlists')
            ->where('auto_fetch_series_metadata', true)
            ->update(['auto_sync_series_stream_files' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('auto_sync_series_stream_files');
        });
    }
};
