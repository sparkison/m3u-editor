<?php

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->string('user_agent')
                ->nullable()
                ->after('channel_start');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->string('user_agent')
                ->nullable()
                ->after('channel_start');
        });
        CustomPlaylist::query()->update(['user_agent' => $this->userAgent]);
        MergedPlaylist::query()->update(['user_agent' => $this->userAgent]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn(['user_agent']);
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn(['user_agent']);
        });
    }
};
