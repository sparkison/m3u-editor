<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->integer('selected_xtream_config_index')
                ->default(0)
                ->after('xtream_config');
        });
    }

    public function down(): void
    {
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropColumn('selected_xtream_config_index');
        });
    }
};
