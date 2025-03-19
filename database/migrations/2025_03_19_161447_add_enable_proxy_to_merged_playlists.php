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
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('enable_proxy')->default(false)->after('channel_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_playlists', function (Blueprint $table) {
            //
        });
    }
};
