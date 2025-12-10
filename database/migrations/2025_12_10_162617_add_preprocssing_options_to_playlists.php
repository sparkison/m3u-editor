<?php

use App\Models\Playlist;
use App\Models\SourceGroup;
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
        Schema::disableForeignKeyConstraints();

        Schema::table('source_groups', function (Blueprint $table) {
            $table->dropUnique(['name', 'playlist_id']);
            $table->string('type')->default('live');
            $table->unique(['name', 'playlist_id', 'type']);
        });

        Schema::enableForeignKeyConstraints();

        // Now we need to copy the existing source groups to have both types
        SourceGroup::query()
            ->where('type', 'live')
            ->get()
            ->each(function (SourceGroup $sourceGroup) {
                $newSourceGroup = $sourceGroup->replicate();
                $newSourceGroup->type = 'vod';
                $newSourceGroup->pushQuietly();
            });

        // Next, we need to copy playlist preprocessing options from playlists to source groups
        foreach (Playlist::query()->where('xtream', true)->cursor() as $playlist) {
            $prefs = $playlist->import_prefs;
            if ($prefs && is_array($prefs)) {
                $update = $prefs;

                // See if using selected groups
                if ($prefs['selected_groups'] ?? false) {
                    $update['selected_vod_groups'] = $prefs['selected_groups'];
                }

                // See if using group prefixes
                if ($prefs['included_group_prefixes'] ?? false) {
                    $update['included_vod_group_prefixes'] = $prefs['included_group_prefixes'];
                }

                if (! empty($update)) {
                    $playlist->update([
                        'import_prefs' => $update,
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SourceGroup::query()
            ->where('type', 'vod')
            ->delete();

        Schema::disableForeignKeyConstraints();

        Schema::table('source_groups', function (Blueprint $table) {
            $table->dropUnique(['name', 'playlist_id', 'type']);
            $table->dropColumn('type');
            $table->unique(['name', 'playlist_id']);
        });

        Schema::enableForeignKeyConstraints();
    }
};
