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
            $table->boolean('dummy_epg')
                ->default(false)
                ->after('id_channel_by');
            $table->string('dummy_epg_length')
                ->nullable()
                ->after('dummy_epg');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('dummy_epg')
                ->default(false)
                ->after('id_channel_by');
            $table->string('dummy_epg_length')
                ->nullable()
                ->after('dummy_epg');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('dummy_epg')
                ->default(false)
                ->after('id_channel_by');
            $table->string('dummy_epg_length')
                ->nullable()
                ->after('dummy_epg');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn(['dummy_epg', 'dummy_epg_length']);
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn(['dummy_epg', 'dummy_epg_length']);
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn(['dummy_epg', 'dummy_epg_length']);
        });
    }
};
