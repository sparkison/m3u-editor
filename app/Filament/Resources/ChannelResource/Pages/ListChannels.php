<?php

namespace App\Filament\Resources\ChannelResource\Pages;

use App\Filament\Exports\ChannelExporter;
use App\Filament\Imports\ChannelImporter;
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
            Actions\ImportAction::make()
                ->importer(ChannelImporter::class)
                ->label('Import Channels')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('primary')
                ->modalDescription('Import channels from a CSV or XLSX file.'),
            Actions\ExportAction::make()
                ->exporter(ChannelExporter::class)
                ->label('Export Channels')
                ->icon('heroicon-m-arrow-up-tray')
                ->color('primary')
                ->modalDescription('Export channels to a CSV or XLSX file. NOTE: Only enabled channels will be exported.')
                ->columnMapping(false)
                ->modifyQueryUsing(function ($query, array $options) {
                    // For now, only allow exporting enabled channels
                    return $query->where([
                        ['playlist_id', $options['playlist']],
                        ['enabled', true],
                    ]);
                    // return $query->where('playlist_id', $options['playlist'])
                    //     ->when($options['enabled'], function ($query, $enabled) {
                    //         return $query->where('enabled', $enabled);
                    //     });
                }),
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
