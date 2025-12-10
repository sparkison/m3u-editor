<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Let's clear out some old FFmpeg and proxy settings that are no longer needed or used
        $settingsToDelete = [
            // VAAPI and QSV settings
            'general.hardware_acceleration_method',
            'general.ffmpeg_custom_command_template',
            'general.ffmpeg_vaapi_device',
            'general.ffmpeg_vaapi_video_filter',
            'general.ffmpeg_qsv_device',
            'general.ffmpeg_qsv_video_filter',
            'general.ffmpeg_qsv_encoder_options',
            'general.ffmpeg_qsv_additional_args',

            // FFmpeg general settings
            'general.ffmpeg_user_agent',
            'general.ffmpeg_debug',
            'general.ffmpeg_max_tries',
            'general.ffmpeg_codec_video',
            'general.ffmpeg_codec_audio',
            'general.ffmpeg_codec_subtitles',

            // FFmpeg path and HLS settings
            'general.ffmpeg_path',
            'general.ffprobe_path',
            'general.ffmpeg_hls_time',
            'general.ffmpeg_ffprobe_timeout',
            'general.hls_playlist_max_attempts',
            'general.hls_playlist_sleep_seconds',
        ];

        // Remove old settings (if they exist)
        foreach ($settingsToDelete as $setting) {
            if ($this->migrator->exists($setting)) {
                $this->migrator->delete($setting);
            }
        }

        // Now let's add the new proxy override settings
        if (! $this->migrator->exists('general.url_override')) {
            $this->migrator->add('general.url_override', '');
        }
        if (! $this->migrator->exists('general.url_override_include_logos')) {
            $this->migrator->add('general.url_override_include_logos', false);
        }
    }
};
