<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Series stream file sync filename options
        if (!$this->migrator->exists('general.stream_file_sync_filename_year')) {
            $this->migrator->add('general.stream_file_sync_filename_year', false);
        }
        if (!$this->migrator->exists('general.stream_file_sync_filename_resolution')) {
            $this->migrator->add('general.stream_file_sync_filename_resolution', false);
        }
        if (!$this->migrator->exists('general.stream_file_sync_filename_codec')) {
            $this->migrator->add('general.stream_file_sync_filename_codec', false);
        }
        if (!$this->migrator->exists('general.stream_file_sync_filename_tmdb_id')) {
            $this->migrator->add('general.stream_file_sync_filename_tmdb_id', false);
        }
        if (!$this->migrator->exists('general.stream_file_sync_tmdb_id_format')) {
            $this->migrator->add('general.stream_file_sync_tmdb_id_format', 'square');
        }
        if (!$this->migrator->exists('general.stream_file_sync_clean_special_chars')) {
            $this->migrator->add('general.stream_file_sync_clean_special_chars', true);
        }
        if (!$this->migrator->exists('general.stream_file_sync_remove_consecutive_chars')) {
            $this->migrator->add('general.stream_file_sync_remove_consecutive_chars', true);
        }
        if (!$this->migrator->exists('general.stream_file_sync_replace_char')) {
            $this->migrator->add('general.stream_file_sync_replace_char', 'space');
        }

        // VOD stream file sync filename options
        if (!$this->migrator->exists('general.vod_stream_file_sync_filename_year')) {
            $this->migrator->add('general.vod_stream_file_sync_filename_year', false);
        }
        if (!$this->migrator->exists('general.vod_stream_file_sync_filename_resolution')) {
            $this->migrator->add('general.vod_stream_file_sync_filename_resolution', false);
        }
        if (!$this->migrator->exists('general.vod_stream_file_sync_filename_codec')) {
            $this->migrator->add('general.vod_stream_file_sync_filename_codec', false);
        }
        if (!$this->migrator->exists('general.vod_stream_file_sync_filename_tmdb_id')) {
            $this->migrator->add('general.vod_stream_file_sync_filename_tmdb_id', false);
        }
        if (!$this->migrator->exists('general.vod_stream_file_sync_tmdb_id_format')) {
            $this->migrator->add('general.vod_stream_file_sync_tmdb_id_format', 'square');
        }
        if (!$this->migrator->exists('general.vod_stream_file_sync_clean_special_chars')) {
            $this->migrator->add('general.vod_stream_file_sync_clean_special_chars', true);
        }
        if (!$this->migrator->exists('general.vod_stream_file_sync_remove_consecutive_chars')) {
            $this->migrator->add('general.vod_stream_file_sync_remove_consecutive_chars', true);
        }
        if (!$this->migrator->exists('general.vod_stream_file_sync_replace_char')) {
            $this->migrator->add('general.vod_stream_file_sync_replace_char', 'space');
        }
    }
};
