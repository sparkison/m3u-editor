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
            $table->boolean('include_vod_in_epg')->default(false)
                ->nullable()
                ->after('include_vod_in_m3u')
                ->comment('When enabled, VOD channels will be included in the generated EPG output.');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('include_vod_in_epg')->default(false)
                ->nullable()
                ->after('include_vod_in_m3u')
                ->comment('When enabled, VOD channels will be included in the generated EPG output.');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('include_vod_in_epg')->default(false)
                ->nullable()
                ->after('include_vod_in_m3u')
                ->comment('When enabled, VOD channels will be included in the generated EPG output.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('include_vod_in_epg');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('include_vod_in_epg');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('include_vod_in_epg');
        });
    }
};
