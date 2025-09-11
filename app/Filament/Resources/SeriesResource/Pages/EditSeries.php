<?php

namespace App\Filament\Resources\SeriesResource\Pages;

use App\Filament\Resources\SeriesResource;
use Filament\Actions;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\EditRecord;

class EditSeries extends EditRecord
{
    protected static string $resource = SeriesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ActionGroup::make([
                Actions\Action::make('process')
                    ->label('Fetch Series Metadata')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                                playlistSeries: $record,
                            ));
                    })->after(function () {
                        FilamentNotification::make()
                            ->success()
                            ->title('Series is being processed')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Process series now? This will fetch all episodes and seasons for this series.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Actions\Action::make('sync')
                    ->label('Sync Series .strm files')
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\SyncSeriesStrmFiles(
                                series: $record,
                            ));
                    })->after(function () {
                        FilamentNotification::make()
                            ->success()
                            ->title('Series .strm files are being synced')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription('Sync series .strm files now? This will generate .strm files for this series at the path set for this series.')
                    ->modalSubmitActionLabel('Yes, sync now'),

                Actions\Action::make('enable')
                    ->label('Enable all episodes')
                    ->action(function ($record): void {
                        $record->episodes()->update([
                            'enabled' => true,
                        ]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        FilamentNotification::make()
                            ->success()
                            ->title('Series episodes enabled')
                            ->body('The series episodes have been enabled.')
                            ->send();
                    })
                    ->color('success')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-check-circle')
                    ->modalIcon('heroicon-o-check-circle')
                    ->modalDescription('Enable the series episodes now?')
                    ->modalSubmitActionLabel('Yes, enable now'),
                Actions\Action::make('disable')
                    ->label('Disable all episodes')
                    ->action(function ($record): void {
                        $record->episodes()->update([
                            'enabled' => false,
                        ]);
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        FilamentNotification::make()
                            ->success()
                            ->title('Series episodes disabled')
                            ->body('The series episodes have been disabled.')
                            ->send();
                    })
                    ->color('warning')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription('Disable the series episodes now?')
                    ->modalSubmitActionLabel('Yes, disable now'),

                Actions\DeleteAction::make()
                    ->modalIcon('heroicon-o-trash')
                    ->modalDescription('Are you sure you want to delete this series? This will delete all episodes and seasons for this series. This action cannot be undone.')
                    ->modalSubmitActionLabel('Yes, delete series'),
            ])->button()
        ];
    }
}
