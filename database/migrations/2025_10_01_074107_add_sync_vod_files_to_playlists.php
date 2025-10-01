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
            $table->boolean('auto_fetch_vod_metadata')->default(false)
                ->after('auto_fetch_series_metadata');
            $table->boolean('auto_sync_vod_stream_files')->default(false)
                ->after('auto_fetch_vod_metadata');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('auto_sync_vod_stream_files');
            $table->dropColumn('auto_fetch_vod_metadata');
        });
    }
};
