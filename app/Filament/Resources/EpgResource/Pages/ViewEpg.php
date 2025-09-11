<?php

namespace App\Filament\Resources\EpgResource\Pages;

use App\Enums\Status;
use App\Filament\Resources\EpgResource;
use App\Filament\Resources\EpgResource\Widgets;
use App\Livewire\EpgViewer;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\ViewRecord;

class ViewEpg extends ViewRecord
{
    protected static string $resource = EpgResource::class;

    public function getVisibleHeaderWidgets(): array
    {
        return [
            Widgets\ImportProgress::class
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Process')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    $record = $this->getRecord();
                    $record->update([
                        'status' => Status::Processing,
                        'progress' => 0,
                        'sd_progress' => 0,
                        'cache_progress' => 0,
                    ]);
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new \App\Jobs\ProcessEpgImport($record, force: true));
                })->after(function () {
                    FilamentNotification::make()
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
                ->label('Generate Cache')
                ->icon('heroicon-o-arrows-pointing-in')
                ->color('gray')
                ->action(function () {
                    $record = $this->getRecord();
                    $record->update([
                        'status' => Status::Processing,
                        'cache_progress' => 0,
                    ]);
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new \App\Jobs\GenerateEpgCache($record->uuid, notify: true));
                })->after(function () {
                    FilamentNotification::make()
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
        $record = $this->getRecord();
        //if ($record->channel_count === 0) {
            $record->loadCount('channels');
            $record->channel_count = $record->channels_count;
        //}
        $record->programme_count = $record->programme_count ?: ($record->cache_meta['total_programmes'] ?? 0);
        return $infolist
            ->schema([
                Infolists\Components\Section::make('EPG Information')
                    ->collapsible(true)
                    ->compact()
                    ->persistCollapsed(true)
                    ->schema([
                        Infolists\Components\Grid::make()
                            ->columns(2)
                            ->columnSpan(2)
                            ->schema([
                                Infolists\Components\Grid::make()
                                    ->columnSpan(1)
                                    ->columns(1)
                                    ->schema([
                                        Infolists\Components\TextEntry::make('name')
                                            ->label('Name'),
                                        Infolists\Components\TextEntry::make('status')
                                            ->badge()
                                            ->color(fn($state) => $state?->getColor()),
                                        Infolists\Components\TextEntry::make('synced')
                                            ->label('Last Synced')
                                            ->since()
                                            ->placeholder('Never'),
                                    ]),
                                Infolists\Components\Grid::make()
                                    ->columnSpan(1)
                                    ->columns(1)
                                    ->schema([
                                        Infolists\Components\IconEntry::make('is_cached')
                                            ->label('Cached')
                                            ->icon(fn(string $state): string => match ($state) {
                                                '1' => 'heroicon-o-check-circle',
                                                '0' => 'heroicon-o-x-mark',
                                                default => 'heroicon-o-x-mark',
                                            }),
                                        Infolists\Components\TextEntry::make('channel_count')
                                            ->label('Total Channels')
                                            ->badge(),
                                        Infolists\Components\TextEntry::make('programme_count')
                                            ->label('Total Programmes')
                                            ->badge(),
                                    ]),
                            ]),
                        Infolists\Components\Grid::make()
                            ->columns(1)
                            ->columnSpan(1)
                            ->schema([

                                Infolists\Components\KeyValueEntry::make('cached_epg_meta')
                                    ->label('Cache Metadata')
                            ])
                    ])
                    ->columns(3),

                Infolists\Components\Livewire::make(EpgViewer::class)
                    ->columnSpanFull(),
            ]);
    }
}
