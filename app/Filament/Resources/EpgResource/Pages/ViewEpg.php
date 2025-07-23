<?php

namespace App\Filament\Resources\EpgResource\Pages;

use App\Filament\Infolists\Components\EpgViewer;
use App\Filament\Resources\EpgResource;
use Filament\Actions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewEpg extends ViewRecord
{
    protected static string $resource = EpgResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh EPG')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $record = $this->getRecord();
                    $record->update([
                        'status' => \App\Enums\Status::Processing,
                        'progress' => 0,
                    ]);
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new \App\Jobs\ProcessEpgImport($record, force: true));
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title('EPG is processing')
                        ->body('EPG is being processed in the background. The view will update when complete.')
                        ->duration(5000)
                        ->send();
                })
                ->disabled(fn() => $this->getRecord()->status === \App\Enums\Status::Processing)
                ->requiresConfirmation()
                ->modalDescription('Process EPG now? This will reload the EPG data from the source.')
                ->modalSubmitActionLabel('Yes, refresh now'),

            Actions\Action::make('cache')
                ->label('Generate EPG Cache')
                ->icon('heroicon-o-arrows-pointing-in')
                ->action(function () {
                    $record = $this->getRecord();
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new \App\Jobs\GenerateEpgCache($record->uuid, notify: true));
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title('EPG Cache is being generated')
                        ->body('EPG Cache is being generated in the background. You will be notified when complete.')
                        ->duration(5000)
                        ->send();
                })
                ->disabled(fn() => $this->getRecord()->status === \App\Enums\Status::Processing)
                ->requiresConfirmation()
                ->modalDescription('Generate EPG Cache now? This will create a cache for the EPG data.')
                ->modalSubmitActionLabel('Yes, generate cache now'),

            Actions\Action::make('download')
                ->label('Download EPG')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn() => route('epg.file', ['uuid' => $this->getRecord()->uuid]))
                ->openUrlInNewTab(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('EPG Information')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn($state) => $state?->getColor()),
                        TextEntry::make('synced')
                            ->label('Last Synced')
                            ->since()
                            ->placeholder('Never'),
                        TextEntry::make('channels_count')
                            ->label('Total Channels'),
                    ])
                    ->columns(2),

                Section::make('EPG Guide')
                    ->schema([
                        EpgViewer::make(),
                    ])
                    ->collapsible(false),
            ]);
    }
}
