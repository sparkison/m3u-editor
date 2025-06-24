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
            $table->unsignedBigInteger('total_channel_count')
                ->nullable()
                ->after('channel_count');

            $table->unsignedBigInteger('current_mapped_count')
                ->nullable()
                ->after('mapped_count');
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
