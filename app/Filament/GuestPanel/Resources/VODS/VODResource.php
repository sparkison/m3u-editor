<?php

namespace App\Filament\GuestPanel\Resources\VODS;

use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Models\Channel;
use App\Models\VOD;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

class VODResource extends Resource
{
    use HasPlaylist;

    protected static ?string $model = Channel::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-s-film';
    protected static ?string $navigationLabel = 'VOD';
    protected static ?string $slug = 'vod';

    public static function getUrl(
        ?string $name = null,
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
        bool $shouldGuessMissingParameters = false
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();

        // Default to 'index' if $name is not provided
        $routeName = static::getRouteBaseName($panel) . '.' . ($name ?? 'index');

        return route($routeName, $parameters, $isAbsolute);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
            ])
            ->filters([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVODS::route('/'),
        ];
    }
}
