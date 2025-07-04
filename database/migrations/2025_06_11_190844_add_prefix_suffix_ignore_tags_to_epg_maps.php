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
            $table->jsonb('settings')->after('recurring')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epg_maps', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
