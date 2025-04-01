<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlaylistAuthResource\Pages;
use App\Filament\Resources\PlaylistAuthResource\RelationManagers;
use App\Models\PlaylistAuth;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlaylistAuthResource extends Resource
{
    protected static ?string $model = PlaylistAuth::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'username'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                // Tables\Columns\TextColumn::make('password')
                //     ->searchable()
                //     ->sortable()
                //     ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->toggleable()
                    ->tooltip('Toggle auth status')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PlaylistsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlaylistAuths::route('/'),
            'create' => Pages\CreatePlaylistAuth::route('/create'),
            'edit' => Pages\EditPlaylistAuth::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\Section::make('Playlist Auth')
                ->description('Auth configuration')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Name')
                        ->required()
                        ->columnSpan(1),
                    Forms\Components\Toggle::make('enabled')
                        ->label('Enabled')
                        ->columnSpan(1)
                        ->inline(false)
                        ->default(true),
                    Forms\Components\TextInput::make('username')
                        ->label('Username')
                        ->required()
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->required()
                        ->revealable()
                        ->columnSpan(1),
                ])
                ->columns(2),
        ];
    }
}
