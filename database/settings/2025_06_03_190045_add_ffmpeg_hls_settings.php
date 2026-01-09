<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('ffmpeg_hls_time', 4);
            $blueprint->add('ffmpeg_ffprobe_timeout', 5);
            $blueprint->add('hls_playlist_max_attempts', 10);
            $blueprint->add('hls_playlist_sleep_seconds', 1.0);
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            $blueprint->delete('ffmpeg_hls_time');
            $blueprint->delete('ffmpeg_ffprobe_timeout');
            $blueprint->delete('hls_playlist_max_attempts');
            $blueprint->delete('hls_playlist_sleep_seconds');
        });
    }
};
