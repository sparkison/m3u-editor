<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('epg_channels', function (Blueprint $table) {
            $table->text('icon_custom')->nullable();
            $table->text('name_custom')->nullable();
            $table->text('display_name_custom')->nullable();
        });

        // Copy the existing columns values to the "custom" columns
        DB::table('epg_channels')->update(['icon_custom' => DB::raw('icon')]);
        DB::table('epg_channels')->update(['name_custom' => DB::raw('name')]);
        DB::table('epg_channels')->update(['display_name_custom' => DB::raw('display_name')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epg_channels', function (Blueprint $table) {
            $table->dropColumn('icon_custom');
            $table->dropColumn('name_custom');
            $table->dropColumn('display_name_custom');
        });
    }
};
