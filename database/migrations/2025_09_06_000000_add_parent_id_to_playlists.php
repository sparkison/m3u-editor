<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('playlists')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        Schema::table('shared_streams', function (Blueprint $table) {
            $table->string('format')->default('ts')->change();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->unique(
                ['playlist_id', 'source_category_id'],
                'categories_playlist_id_source_category_id_unique'
            );
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->unique(['playlist_id', 'name_internal'], 'groups_playlist_id_name_internal_unique');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->unique(
                ['playlist_id', 'source_series_id'],
                'series_playlist_id_source_series_id_unique'
            );
        });

        Schema::table('seasons', function (Blueprint $table) {
            $table->unique(
                ['playlist_id', 'source_season_id'],
                'seasons_playlist_id_source_season_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropUnique('seasons_playlist_id_source_season_id_unique');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dropUnique('series_playlist_id_source_series_id_unique');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropUnique('groups_playlist_id_name_internal_unique');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_playlist_id_source_category_id_unique');
        });

        Schema::table('shared_streams', function (Blueprint $table) {
            $table->string('format')->nullable()->default(null)->change();
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
