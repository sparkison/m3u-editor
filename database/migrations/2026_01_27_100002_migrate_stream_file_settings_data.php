<?php

use App\Models\StreamFileSetting;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Get the admin user (or first user)
        $user = User::where('email', config('dev.admin_emails')[0])->first() ?? User::first();

        if (! $user) {
            return; // No users yet, nothing to migrate
        }

        $settings = app(GeneralSettings::class);

        // Only migrate if there are existing sync settings enabled
        $seriesEnabled = $settings->stream_file_sync_enabled ?? false;
        $vodEnabled = $settings->vod_stream_file_sync_enabled ?? false;

        // Create default Series Stream File Setting from existing settings
        if ($seriesEnabled || $settings->stream_file_sync_location) {
            $seriesProfile = StreamFileSetting::create([
                'user_id' => $user->id,
                'name' => 'Default Series Settings (Migrated)',
                'description' => 'Automatically migrated from global settings',
                'type' => 'series',
                'enabled' => $seriesEnabled,
                'location' => $settings->stream_file_sync_location,
                'path_structure' => $settings->stream_file_sync_path_structure ?? ['category', 'series', 'season'],
                'filename_metadata' => $settings->stream_file_sync_filename_metadata ?? [],
                'tmdb_id_format' => $settings->stream_file_sync_tmdb_id_format ?? 'square',
                'clean_special_chars' => $settings->stream_file_sync_clean_special_chars ?? true,
                'remove_consecutive_chars' => $settings->stream_file_sync_remove_consecutive_chars ?? true,
                'replace_char' => $settings->stream_file_sync_replace_char ?? 'space',
                'name_filter_enabled' => $settings->stream_file_sync_name_filter_enabled ?? false,
                'name_filter_patterns' => $settings->stream_file_sync_name_filter_patterns ?? [],
                'generate_nfo' => $settings->stream_file_sync_generate_nfo ?? false,
            ]);

            // Update the global default
            $settings->default_series_stream_file_setting_id = $seriesProfile->id;
        }

        // Create default VOD Stream File Setting from existing settings
        if ($vodEnabled || $settings->vod_stream_file_sync_location) {
            $vodProfile = StreamFileSetting::create([
                'user_id' => $user->id,
                'name' => 'Default VOD Settings (Migrated)',
                'description' => 'Automatically migrated from global settings',
                'type' => 'vod',
                'enabled' => $vodEnabled,
                'location' => $settings->vod_stream_file_sync_location,
                'path_structure' => $settings->vod_stream_file_sync_path_structure ?? ['group', 'title'],
                'filename_metadata' => $settings->vod_stream_file_sync_filename_metadata ?? [],
                'tmdb_id_format' => $settings->vod_stream_file_sync_tmdb_id_format ?? 'square',
                'clean_special_chars' => $settings->vod_stream_file_sync_clean_special_chars ?? true,
                'remove_consecutive_chars' => $settings->vod_stream_file_sync_remove_consecutive_chars ?? true,
                'replace_char' => $settings->vod_stream_file_sync_replace_char ?? 'space',
                'name_filter_enabled' => $settings->vod_stream_file_sync_name_filter_enabled ?? false,
                'name_filter_patterns' => $settings->vod_stream_file_sync_name_filter_patterns ?? [],
                'generate_nfo' => $settings->vod_stream_file_sync_generate_nfo ?? false,
            ]);

            // Update the global default
            $settings->default_vod_stream_file_setting_id = $vodProfile->id;
        }

        // Save the updated settings
        $settings->save();
    }

    public function down(): void
    {
        // Remove migrated profiles (be careful not to delete user-created ones)
        StreamFileSetting::where('name', 'like', '% (Migrated)')->delete();

        // Reset the default IDs
        $settings = app(GeneralSettings::class);
        $settings->default_series_stream_file_setting_id = null;
        $settings->default_vod_stream_file_setting_id = null;
        $settings->save();
    }
};
