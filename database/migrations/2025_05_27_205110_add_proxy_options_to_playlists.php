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
            $table->jsonb('proxy_options')->after('enable_proxy')->nullable();
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->jsonb('proxy_options')->after('enable_proxy')->nullable();
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->jsonb('proxy_options')->after('enable_proxy')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('proxy_options');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('proxy_options');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('proxy_options');
        });
    }
};
