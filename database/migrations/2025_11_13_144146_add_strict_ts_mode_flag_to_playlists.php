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
            $table->boolean('strict_live_ts')->default(false)
                ->nullable()
                ->after('vod_stream_profile_id');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('strict_live_ts')->default(false)
                ->nullable()
                ->after('vod_stream_profile_id');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('strict_live_ts')->default(false)
                ->nullable()
                ->after('vod_stream_profile_id');
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->boolean('strict_live_ts')->default(false)
                ->nullable()
                ->after('vod_stream_profile_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('strict_live_ts');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('strict_live_ts');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('strict_live_ts');
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropColumn('strict_live_ts');
        });
    }
};
