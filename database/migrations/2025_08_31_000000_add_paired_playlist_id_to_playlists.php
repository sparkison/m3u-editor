<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->foreignId('paired_playlist_id')->nullable()->constrained('playlists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paired_playlist_id');
        });
    }
};
