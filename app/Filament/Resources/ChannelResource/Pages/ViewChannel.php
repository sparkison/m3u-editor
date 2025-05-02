<?php

namespace App\Filament\Resources\ChannelResource\Pages;

use App\Filament\Resources\ChannelResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewChannel extends ViewRecord
{
    protected static string $resource = ChannelResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return ChannelResource::infolist($infolist);
    }
}
