<?php

namespace App\Filament\Resources\Channels\Pages;

use Filament\Schemas\Schema;
use App\Filament\Resources\Channels\ChannelResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\Pages\ViewRecord;

class ViewChannel extends ViewRecord
{
    protected static string $resource = ChannelResource::class;

    public function infolist(Schema $schema): Schema
    {
        return ChannelResource::infolist($schema);
    }
}
