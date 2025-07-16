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
        Schema::table('channel_failovers', function (Blueprint $table) {
            $table->unique(['channel_id', 'channel_failover_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_failovers', function (Blueprint $table) {
            $table->dropUnique(['channel_id', 'channel_failover_id']);
        });
    }
};
