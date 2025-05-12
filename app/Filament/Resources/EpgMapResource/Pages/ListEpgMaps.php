<?php

namespace App\Filament\Resources\EpgMapResource\Pages;

use App\Filament\Resources\EpgMapResource;
use App\Models\Epg;
use App\Models\Playlist;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ListEpgMaps extends ListRecords
{
    protected static string $resource = EpgMapResource::class;

    protected ?string $subheading = 'View the EPG channel mapping jobs and progress here. Head to channels to create a new mapping.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('map')
                ->label('Map EPG to Playlist')
                ->form([
                    Forms\Components\Select::make('epg')
                        ->required()
                        ->label('EPG')
                        ->helperText('Select the EPG you would like to map from.')
                        ->options(Epg::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable(),
                    Forms\Components\Select::make('playlist')
                        ->required()
                        ->label('Playlist')
                        ->helperText('Select the playlist you would like to map to.')
                        ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable(),
                    Forms\Components\Toggle::make('overwrite')
                        ->label('Overwrite')
                        ->helperText('Overwrite channels with existing mappings?')
                        ->default(false),
                    Forms\Components\Toggle::make('recurring')
                        ->label('Recurring')
                        ->helperText('Re-run this mapping everytime the EPG is synced?')
                        ->default(false),
                ])
                ->action(function (Collection $records, array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new \App\Jobs\MapPlaylistChannelsToEpg(
                            epg: (int)$data['epg'],
                            playlist: $data['playlist'],
                            force: $data['overwrite'],
                            recurring: $data['recurring'],
                        ));
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title('EPG to Channel mapping')
                        ->body('Channel mapping started, you will be notified when the process is complete.')
                        ->send();
                })
                ->requiresConfirmation()
                ->icon('heroicon-o-link')
                ->modalIcon('heroicon-o-link')
                ->modalDescription('Map the selected EPG to the selected Playlist channels.')
                ->modalSubmitActionLabel('Map now'),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc');
    }
}
