<?php

namespace App\Filament\GuestPanel\Resources;

use App\Models\Playlist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

class PlaylistResource extends Resource
{
    protected static ?string $model = Playlist::class;
    protected static ?string $navigationLabel = 'Playlist';
    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->sortable()->searchable(),
                TextColumn::make('created_at')->dateTime(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        // Only show the playlist attached to the request (from middleware)
        $playlist = request()->attributes->get('playlist');
        return Playlist::query()->where('id', $playlist?->id ?? 0);
    }
}
