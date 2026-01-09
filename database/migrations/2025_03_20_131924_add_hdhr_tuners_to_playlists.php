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
            $table->integer('streams')
                ->default(1)
                ->after('xtream_status');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->integer('streams')
                ->default(1)
                ->after('enable_proxy');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->integer('streams')
                ->default(1)
                ->after('enable_proxy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('streams');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('streams');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('streams');
        });
    }
};
