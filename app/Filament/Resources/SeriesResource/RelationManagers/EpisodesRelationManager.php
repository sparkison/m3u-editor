<?php

namespace App\Filament\Resources\SeriesResource\RelationManagers;

use App\Infolists\Components\SeriesPreview;
use App\Livewire\ChannelStreamStats;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EpisodesRelationManager extends RelationManager
{
    protected static string $relationship = 'episodes';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['season', 'series']);
            })
            ->defaultGroup('season')
            ->defaultSort('episode_num', 'asc')
            ->contentGrid([
                'md' => 2,
                'lg' => 3,
                'xl' => 4,
            ])
            ->paginated([12, 24, 48, 100])
            ->defaultPaginationPageOption(12)
            ->columns([
                Tables\Columns\Layout\Stack::make([
                    Tables\Columns\ImageColumn::make('info.movie_image')
                        ->label('')
                        ->height(200)
                        ->width('full')
                        ->checkFileExistence(false)
                        ->defaultImageUrl('/episode-placeholder.png')
                        ->extraImgAttributes(['class' => 'episode-placeholder rounded-t-lg object-cover w-full h-48'])
                        ->getStateUsing(function ($record) {
                            $info = $record->info ?? [];
                            return $info['movie_image'] ?? $info['cover_big'] ?? url('/episode-placeholder.png');
                        }),

                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('title')
                            ->weight('semibold')
                            ->size('sm')
                            ->limit(50)
                            ->tooltip(fn($record) => $record->title),

                        Tables\Columns\TextColumn::make('episode_info')
                            ->label('')
                            ->size('xs')
                            ->color('gray')
                            ->getStateUsing(function ($record) {
                                $seasonName = $record->season ? "Season {$record->season}" : 'Unknown Season';
                                $episodeNum = $record->episode_num ? "Episode {$record->episode_num}" : '';
                                return trim("{$seasonName} {$episodeNum}");
                            }),

                        Tables\Columns\TextColumn::make('info.plot')
                            ->label('')
                            ->limit(100)
                            ->size('xs')
                            ->color('gray')
                            ->tooltip(fn($record) => $record->info['plot'] ?? null)
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];
                                return $info['plot'] ?? null;
                            })
                            ->placeholder('No description available'),

                        Tables\Columns\Layout\Grid::make(2)
                            ->schema([
                                Tables\Columns\TextColumn::make('info.duration')
                                    ->label('Duration')
                                    ->badge()
                                    ->size('xs')
                                    ->color('primary')
                                    ->icon('heroicon-m-clock')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];
                                        return $info['duration'] ?? null;
                                    }),

                                Tables\Columns\TextColumn::make('info.rating')
                                    ->label('Rating')
                                    ->badge()
                                    ->size('xs')
                                    ->color('success')
                                    ->icon('heroicon-m-star')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];
                                        return $info['rating'] ?? null;
                                    }),
                            ]),

                        Tables\Columns\TextColumn::make('info.release_date')
                            ->label('')
                            ->date()
                            ->size('xs')
                            ->color('gray')
                            ->prefix('Released: ')
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];
                                return $info['release_date'] ?? null;
                            })
                            ->placeholder(''),
                    ])->space(2)->extraAttributes(['class' => 'p-4']),
                ])->space(0)->extraAttributes(['class' => 'bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden']),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->slideOver()
                    ->hiddenLabel()
                    ->icon('heroicon-m-play')
                    ->button()
                    ->size('xs')
                    ->tooltip('Play Episode'),
            ])
            ->bulkActions([
                // @TODO - add download? Would need to generate streamlink files and compress then download...
            ]);
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                SeriesPreview::make('preview')
                    ->columnSpanFull()
                    ->hiddenLabel(),
                Infolists\Components\Section::make('Episode Details')
                    ->collapsible()
                    ->collapsed(true)
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('series.name')
                            ->label('Series'),
                        Infolists\Components\TextEntry::make('season.name')
                            ->label('Season'),
                        Infolists\Components\TextEntry::make('title')
                            ->label('Title')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('episode_num')
                            ->label('Episode Number'),
                        Infolists\Components\TextEntry::make('info.release_date')
                            ->label('Release Date')
                            ->date()
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];
                                return $info['release_date'] ?? null;
                            }),
                    ]),

                Infolists\Components\Section::make('Episode Metadata')
                    ->collapsible()
                    ->collapsed(true)
                    ->columns(3)
                    ->schema([
                        Infolists\Components\ImageEntry::make('info.movie_image')
                            ->label('Episode Image')
                            ->size(200, 300)
                            ->columnSpan(1)
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];
                                return $info['movie_image'] ?? $info['cover_big'] ?? null;
                            }),

                        Infolists\Components\Grid::make()
                            ->columns(2)
                            ->columnSpan(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('info.duration')
                                    ->label('Duration')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];
                                        return $info['duration'] ?? null;
                                    }),
                                Infolists\Components\TextEntry::make('info.rating')
                                    ->label('Rating')
                                    ->badge()
                                    ->color('success')
                                    ->icon('heroicon-m-star')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];
                                        return $info['rating'] ?? null;
                                    }),
                                Infolists\Components\TextEntry::make('info.bitrate')
                                    ->label('Bitrate')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];
                                        $bitrate = $info['bitrate'] ?? null;
                                        return $bitrate ? "{$bitrate} kbps" : null;
                                    }),
                                Infolists\Components\TextEntry::make('info.season')
                                    ->label('Season (Metadata)')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];
                                        return $info['season'] ?? null;
                                    }),
                                Infolists\Components\TextEntry::make('info.tmdb_id')
                                    ->label('TMDB ID')
                                    ->getStateUsing(function ($record) {
                                        $info = $record->info ?? [];
                                        return $info['tmdb_id'] ?? null;
                                    })
                                    ->url(function ($record) {
                                        $info = $record->info ?? [];
                                        $tmdbId = $info['tmdb_id'] ?? null;
                                        return $tmdbId ? "https://www.themoviedb.org/tv/episode/{$tmdbId}" : null;
                                    }, true),
                            ]),

                        Infolists\Components\TextEntry::make('info.plot')
                            ->label('Plot')
                            ->columnSpanFull()
                            ->getStateUsing(function ($record) {
                                $info = $record->info ?? [];
                                return $info['plot'] ?? 'No plot information available.';
                            }),

                        Infolists\Components\TextEntry::make('url')
                            ->label('Stream URL')
                            ->columnSpanFull()
                            ->copyable()
                            ->url(fn($record) => $record->url)
                            ->openUrlInNewTab(),
                    ]),
            ]);
    }

    public function getTabs(): array
    {
        return [];
    }
}
