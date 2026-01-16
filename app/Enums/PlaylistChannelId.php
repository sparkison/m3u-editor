<?php

namespace App\Enums;

enum PlaylistChannelId: string
{
    case TvgId = 'stream_id';
    case ChannelId = 'channel_id';
    case Name = 'name';
    case Number = 'number';
    case Title = 'title';

    public function getColor(): string
    {
        return match ($this) {
            self::TvgId => 'success',
            self::ChannelId => 'gray',
            self::Name => 'gray',
            self::Number => 'gray',
            self::Title => 'gray',
        };
    }

    public function getLabel(): ?string
    {
        return match ($this) {
            self::TvgId => 'TVG ID/Stream ID (default)',
            self::ChannelId => 'Channel ID',
            self::Name => 'Channel Name',
            self::Number => 'Channel Number',
            self::Title => 'Channel Title',
        };
    }
}
