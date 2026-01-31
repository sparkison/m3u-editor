<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropUnique('episodes_source_episode_id_playlist_id_unique');

            $table->unique(['source_episode_id', 'playlist_id', 'series_id']);
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropUnique('episodes_source_episode_id_playlist_id_series_id_unique');

            $table->unique(['source_episode_id', 'playlist_id']);
        });
    }
};
