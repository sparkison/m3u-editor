<?php

namespace App\Settings;

use Filament\Support\Enums\MaxWidth;
use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public ?string $navigation_position = 'left';
    public ?bool $show_breadcrumbs = true;
    public ?bool $show_logs = false;
    public ?bool $show_api_docs = false;
    public ?bool $show_queue_manager = false;
    public ?string $content_width = MaxWidth::ScreenExtraLarge->value;
    public ?string $ffmpeg_user_agent = 'VLC/3.0.21 LibVLC/3.0.21';
    public ?bool $ffmpeg_debug = false;
    public ?int $ffmpeg_max_tries = 3;
    public ?string $ffmpeg_codec_video = '';
    public ?string $ffmpeg_codec_audio = null;
    public ?string $ffmpeg_codec_subtitles = null;
    public ?string $mediaflow_proxy_url = null;
    public ?string $mediaflow_proxy_port = null;
    public ?string $mediaflow_proxy_password = null;
    public ?string $mediaflow_proxy_user_agent = null;
    public ?bool $mediaflow_proxy_playlist_user_agent = false;
    public ?string $ffmpeg_path = null;

    // VAAPI and QSV settings
    public ?string $hardware_acceleration_method = 'none';
    public ?string $ffmpeg_custom_command_template = null;
    // public bool $ffmpeg_vaapi_enabled = false;
    public ?string $ffmpeg_vaapi_device = null;
    public ?string $ffmpeg_vaapi_video_filter = null;
    // public bool $ffmpeg_qsv_enabled = false;
    public ?string $ffmpeg_qsv_device = null;
    public ?string $ffmpeg_qsv_video_filter = null;
    public ?string $ffmpeg_qsv_encoder_options = null;
    public ?string $ffmpeg_qsv_additional_args = null;

    public static function group(): string
    {
        return 'general';
    }
}
