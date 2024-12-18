<?php

namespace App\Filament\Resources\ChannelResource\Pages;

use App\Filament\Resources\ChannelResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListChannels extends ListRecords
{
    protected static string $resource = ChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Channels'),
            'enabled' => Tab::make('Enabled Channels')
                ->modifyQueryUsing(function ($query) {
                    return $query->where('enabled', true);
                }),
            'disabled' => Tab::make('Disabled Channels')
                ->modifyQueryUsing(function ($query) {
                    return $query->where('enabled', false);
                }),
        ];
    }
}
