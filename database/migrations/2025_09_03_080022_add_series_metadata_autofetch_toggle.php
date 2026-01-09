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
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('auto_fetch_series_metadata')->default(false)
                ->nullable()
                ->after('enable_proxy');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dateTime('last_metadata_fetch')->nullable()->after('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('auto_fetch_series_metadata');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dropColumn('last_metadata_fetch');
        });
    }
};
