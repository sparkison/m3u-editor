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
            $table->boolean('enable_custom_headers')->default(false)->after('custom_headers');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('enable_custom_headers')->default(false)->after('custom_headers');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('enable_custom_headers')->default(false)->after('custom_headers');
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->boolean('enable_custom_headers')->default(false)->after('custom_headers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('enable_custom_headers');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('enable_custom_headers');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('enable_custom_headers');
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropColumn('enable_custom_headers');
        });
    }
};

