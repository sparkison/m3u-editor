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
            $table->boolean('short_urls_enabled')
                ->after('enable_proxy')
                ->default(false);
            $table->json('short_urls')
                ->after('short_urls_enabled')
                ->nullable();
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('short_urls_enabled')
                ->after('enable_proxy')
                ->default(false);
            $table->json('short_urls')
                ->after('short_urls_enabled')
                ->nullable();
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('short_urls_enabled')
                ->after('enable_proxy')
                ->default(false);
            $table->json('short_urls')
                ->after('short_urls_enabled')
                ->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn(['short_urls_enabled', 'short_urls']);
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn(['short_urls_enabled', 'short_urls']);
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn(['short_urls_enabled', 'short_urls']);
        });
    }
};
