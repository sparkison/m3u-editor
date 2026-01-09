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
        // Add TMDB fields to channels table (for VOD channels)
        Schema::table('channels', function (Blueprint $table) {
            $table->string('tmdb_id')->nullable()->after('movie_data');
            $table->string('tvdb_id')->nullable()->after('tmdb_id');
            $table->string('imdb_id')->nullable()->after('tvdb_id');
        });

        // Add TMDB fields to series table
        Schema::table('series', function (Blueprint $table) {
            $table->string('tmdb_id')->nullable()->after('source_id');
            $table->string('tvdb_id')->nullable()->after('tmdb_id');
            $table->string('imdb_id')->nullable()->after('tvdb_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['tmdb_id', 'tvdb_id', 'imdb_id']);
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dropColumn(['tmdb_id', 'tvdb_id', 'imdb_id']);
        });
    }
};
