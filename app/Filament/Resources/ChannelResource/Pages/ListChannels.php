<?php

namespace App\Filament\Resources\ChannelResource\Pages;

use App\Filament\Exports\ChannelExporter;
use App\Filament\Imports\ChannelImporter;
use App\Filament\Resources\ChannelResource;
use App\Models\Channel;
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
        return self::tabs();
    }

    public static function tabs($groupId = null): array
    {
        // Change count based on view
        $enabledCount = Channel::query()->where('enabled', true)
            ->when($groupId, function ($query, $groupId) {
                return $query->where('group_id', $groupId);
            })->count();
        $disabledCount = Channel::query()->where('enabled', false)
            ->when($groupId, function ($query, $groupId) {
                return $query->where('group_id', $groupId);
            })->count();

        // Return tabs
        return [
            'all' => Tab::make('All Channels'),
            'enabled' => Tab::make('Enabled Channels')
                // ->icon('heroicon-m-check')
                ->badgeColor('success')
                ->modifyQueryUsing(fn($query) => $query->where('enabled', true))
                ->badge($enabledCount),
            'disabled' => Tab::make('Disabled Channels')
                // ->icon('heroicon-m-x-mark')
                ->badgeColor('danger')
                ->modifyQueryUsing(fn($query) => $query->where('enabled', false))
                ->badge($disabledCount),
        ];
    }
}
