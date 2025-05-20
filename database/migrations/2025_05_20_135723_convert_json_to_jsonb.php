<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->jsonb('import_prefs')->nullable()->change();
            $table->jsonb('xtream_config')->nullable()->change();
            $table->jsonb('xtream_status')->nullable()->change();
            $table->jsonb('short_urls')->nullable()->change();
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->jsonb('short_urls')->nullable()->change();
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->jsonb('short_urls')->nullable()->change();
        });
        Schema::table('channels', function (Blueprint $table) {
            $table->jsonb('extvlcopt')->nullable()->change();
            $table->jsonb('kodidrop')->nullable()->change();
        });
        Schema::table('post_processes', function (Blueprint $table) {
            $table->jsonb('metadata')->change();
        });
        Schema::table('playlist_sync_statuses', function (Blueprint $table) {
            $table->jsonb('sync_stats')->nullable()->change();
        });
        Schema::table('playlist_sync_status_logs', function (Blueprint $table) {
            $table->jsonb('meta')->change();
        });
    }
};
