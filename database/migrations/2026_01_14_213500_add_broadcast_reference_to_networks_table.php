<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->unsignedBigInteger('broadcast_programme_id')->nullable()->after('broadcast_pid');
            $table->integer('broadcast_initial_offset_seconds')->nullable()->after('broadcast_programme_id');

            $table->foreign('broadcast_programme_id')
                ->references('id')
                ->on('network_programmes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropForeign(['broadcast_programme_id']);
            $table->dropColumn(['broadcast_programme_id', 'broadcast_initial_offset_seconds']);
        });
    }
};
