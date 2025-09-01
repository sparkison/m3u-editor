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
        Schema::table('shared_streams', function (Blueprint $table) {
            $table->dropColumn('format');
            $table->enum('format', ['ts', 'hls', 'mkv', 'mp4'])->default('ts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shared_streams', function (Blueprint $table) {
            //
        });
    }
};
