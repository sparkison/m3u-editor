<?php

use App\Models\Playlist;
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
        $xtreamPlaylists = Playlist::where('xtream', true)->get();
        foreach ($xtreamPlaylists as $playlist) {
            $config = $playlist->xtream_config;
            if (in_array('live', $config['import_options'])) {
                continue;
            }
            $config['import_options'][] = 'live';
            $playlist->update([
                'xtream_config' => $config,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
