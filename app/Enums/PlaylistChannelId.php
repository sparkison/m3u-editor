<?php

namespace App\Enums;

enum PlaylistChannelId: string
{
    case TvgId = 'stream_id';
    case ChannelId = 'channel_id';

    public function getColor(): string
    {
        return match ($this) {
            self::TvgId => 'success',
            self::ChannelId => 'gray',
        };
    }

    public function getLabel(): ?string
    {
        return match ($this) {
            self::TvgId => 'TVG ID/Stream ID (default)',
            self::ChannelId => 'Channel Number',
        };
    }
}
