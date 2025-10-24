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
        Schema::disableForeignKeyConstraints();

        // Add vod_stream_profile_id to all playlist tables
        Schema::table('playlists', function (Blueprint $table) {
            $table->foreignId('vod_stream_profile_id')->nullable()
                ->references('id')->on('stream_profiles')
                ->constrained()->nullOnDelete();
        });

        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->foreignId('vod_stream_profile_id')->nullable()
                ->references('id')->on('stream_profiles')
                ->constrained()->nullOnDelete();
        });

        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->foreignId('vod_stream_profile_id')->nullable()
                ->references('id')->on('stream_profiles')
                ->constrained()->nullOnDelete();
        });

        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->foreignId('vod_stream_profile_id')->nullable()
                ->references('id')->on('stream_profiles')
                ->constrained()->nullOnDelete();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropForeign(['vod_stream_profile_id']);
            $table->dropColumn('vod_stream_profile_id');
        });

        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropForeign(['vod_stream_profile_id']);
            $table->dropColumn('vod_stream_profile_id');
        });

        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropForeign(['vod_stream_profile_id']);
            $table->dropColumn('vod_stream_profile_id');
        });

        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropForeign(['vod_stream_profile_id']);
            $table->dropColumn('vod_stream_profile_id');
        });
    }
};
