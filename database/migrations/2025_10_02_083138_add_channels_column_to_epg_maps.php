<?php

use App\Models\EpgMap;
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
        // Need to delete existing entries that do not have a playlist (no way to map channels)
        EpgMap::whereNull('playlist_id')->delete();

        // Add a channels column to epg_maps table to track associated channels
        Schema::table('epg_maps', function (Blueprint $table) {
            $table->jsonb('channels')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epg_maps', function (Blueprint $table) {
            $table->dropColumn('channels');
        });
    }
};
