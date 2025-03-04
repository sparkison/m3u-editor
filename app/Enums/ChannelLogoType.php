<?php

namespace App\Enums;

enum ChannelLogoType: string
{
    case Channel = 'channel';
    case Epg = 'epg';

    public function getColor(): string
    {
        return match ($this) {
            self::Channel => 'success',
            self::Epg => 'gray',
        };
    }

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Channel => 'Channel',
            self::Epg => 'EPG',
        };
    }
}
