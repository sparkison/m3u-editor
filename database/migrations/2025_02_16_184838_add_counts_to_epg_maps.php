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
        Schema::table('epg_maps', function (Blueprint $table) {
            $table->integer('channel_count')->after('sync_time')->default(0);
            $table->integer('mapped_count')->after('channel_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epg_maps', function (Blueprint $table) {
            //
        });
    }
};
