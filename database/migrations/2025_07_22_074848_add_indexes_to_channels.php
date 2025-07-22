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
        Schema::table('channels', function (Blueprint $table) {
            // Core lookup indexes
            $table->index(['user_id', 'playlist_id'], 'idx_channels_user_playlist');
            $table->index(['playlist_id', 'enabled'], 'idx_channels_playlist_enabled');

            // Stream ID indexes for merging/failover operations
            $table->index('stream_id', 'idx_channels_stream_id');
            $table->index('stream_id_custom', 'idx_channels_stream_id_custom');

            // Search and filtering indexes
            $table->index('enabled', 'idx_channels_enabled');
            $table->index('group', 'idx_channels_group');
            $table->index('group_id', 'idx_channels_group_id');

            // Composite index for common query patterns
            $table->index(['user_id', 'enabled', 'playlist_id'], 'idx_channels_user_enabled_playlist');

            // Import/sync related indexes
            $table->index('import_batch_no', 'idx_channels_import_batch');
            $table->index('new', 'idx_channels_new');

            // EPG mapping index
            $table->index('epg_channel_id', 'idx_channels_epg_channel');

            // Sorting and channel number index
            $table->index(['playlist_id', 'sort'], 'idx_channels_playlist_sort');
            $table->index(['playlist_id', 'channel'], 'idx_channels_playlist_channel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex('idx_channels_user_playlist');
            $table->dropIndex('idx_channels_playlist_enabled');
            $table->dropIndex('idx_channels_stream_id');
            $table->dropIndex('idx_channels_stream_id_custom');
            $table->dropIndex('idx_channels_enabled');
            $table->dropIndex('idx_channels_group');
            $table->dropIndex('idx_channels_group_id');
            $table->dropIndex('idx_channels_user_enabled_playlist');
            $table->dropIndex('idx_channels_import_batch');
            $table->dropIndex('idx_channels_new');
            $table->dropIndex('idx_channels_epg_channel');
            $table->dropIndex('idx_channels_playlist_sort');
            $table->dropIndex('idx_channels_playlist_channel');
        });
    }
};
