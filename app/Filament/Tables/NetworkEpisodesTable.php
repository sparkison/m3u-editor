<?php

namespace App\Filament\Tables;

use App\Models\Episode;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NetworkEpisodesTable
{
    public static function configure(Table $table): Table
    {
        $arguments = $table->getArguments();
        $playlistId = $arguments['playlist_id'] ?? null;

        return $table
            ->query(fn (): Builder => Episode::query())
            ->modifyQueryUsing(function (Builder $query) use ($playlistId): Builder {

                $query->with(['series', 'season', 'playlist']);

                if ($playlistId) {
                    $query->where('playlist_id', $playlistId);
                }

                // Only show enabled episodes
                $query->where('enabled', true);

                return $query;
            })
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(15)
            ->defaultSort('title', 'asc')
            ->columns([
                ImageColumn::make('info.movie_image')
                    ->label('Cover')
                    ->height(60)
                    ->width(40)
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['movie_image'] ?? $info['cover_big'] ?? null;
                    })
                    ->defaultImageUrl('/images/placeholder-episode.png'),

                TextColumn::make('title')
                    ->label('Episode Title')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('series.name')
                    ->label('Series')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('season')
                    ->label('Season #')
                    ->searchable(),

                TextColumn::make('episode_num')
                    ->label('Ep #')
                    ->sortable(),

                TextColumn::make('info.duration')
                    ->label('Duration')
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['duration'] ?? null;
                    }),
            ])
            ->filters([
                SelectFilter::make('series')
                    ->label('Series')
                    ->options(fn () => Episode::query()
                        ->distinct()
                        ->pluck('series_id', 'series_id')
                        ->mapWithKeys(function ($seriesId) {
                            $series = \App\Models\Series::find($seriesId);

                            return [$seriesId => $series ? $series->name : 'Unknown'];
                        })
                        ->toArray(),
                    )
                    ->searchable()
                    ->preload(),
                SelectFilter::make('category')
                    ->label('Category')
                    ->relationship('series.category', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->headerActions([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    //
                ]),
            ]);
    }
}
