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
            $table->index(['playlist_id', 'source_category_id']);
        });

        Schema::table('series', function (Blueprint $table) {
            $table->index(['playlist_id', 'source_series_id']);
        });

        Schema::table('seasons', function (Blueprint $table) {
            $table->index(['playlist_id', 'source_season_id']);
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropIndex(['playlist_id', 'source_season_id']);
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dropIndex(['playlist_id', 'source_series_id']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['playlist_id', 'source_category_id']);
        });

        Schema::table('shared_streams', function (Blueprint $table) {
            //
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
