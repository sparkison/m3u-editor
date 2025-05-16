<?php

return [
    'url_override' => env('PROXY_URL_OVERRIDE', null),
    'ffmpeg_additional_args' => env('PROXY_FFMPEG_ADDITIONAL_ARGS', ''),
    'ffmpeg_codec_video' => env('PROXY_FFMPEG_CODEC_VIDEO', null),
    'ffmpeg_codec_audio' => env('PROXY_FFMPEG_CODEC_AUDIO', null),
];