<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Series NFO generation setting
        if (! $this->migrator->exists('general.stream_file_sync_generate_nfo')) {
            $this->migrator->add('general.stream_file_sync_generate_nfo', false);
        }

        // VOD NFO generation setting
        if (! $this->migrator->exists('general.vod_stream_file_sync_generate_nfo')) {
            $this->migrator->add('general.vod_stream_file_sync_generate_nfo', false);
        }
    }
};
