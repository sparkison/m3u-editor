<?php

namespace App\Filament\Resources\PlaylistAliases;

use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use Exception;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class PlaylistAliasResource extends Resource
{
    protected static ?string $model = PlaylistAlias::class;

    protected static ?string $recordTitleAttribute = 'name';
    protected static string | \UnitEnum | null $navigationGroup = 'Playlist';

    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record->name;
    }

    public static function getNavigationSort(): ?int
    {
        return 6;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Toggle::make('enabled')
                    ->default(true)
                    ->columnSpan('full'),
                Forms\Components\TextInput::make('name')
                    ->required(),
                Forms\Components\Textarea::make('description')
                    ->helperText('Optional description for your reference.')
                    ->columnSpanFull(),
                Schemas\Components\Fieldset::make('Alias of (choose one)')
                    ->schema([
                        Forms\Components\Select::make('playlist_id')
                            ->label('Playlist')
                            ->options(fn() => Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state) {
                                    $set('custom_playlist_id', null);
                                    $set('group', null);
                                    $set('group_id', null);
                                }
                            })
                            ->requiredWithout('custom_playlist_id')
                            ->validationMessages([
                                'required_without' => 'Playlist is required if not using a custom playlist.',
                            ])
                            ->rules(['exists:playlists,id']),
                        Forms\Components\Select::make('custom_playlist_id')
                            ->label('Custom Playlist')
                            ->options(fn() => CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state) {
                                    $set('playlist_id', null);
                                    $set('group', null);
                                    $set('group_id', null);
                                }
                            })
                            ->requiredWithout('playlist_id')
                            ->validationMessages([
                                'required_without' => 'Custom Playlist is required if not using a standard playlist.',
                            ])
                            ->dehydrated(true)
                            ->rules(['exists:custom_playlists,id'])
                    ]),

                Schemas\Components\Fieldset::make('Xtream API Config')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('xtream_config.url')
                            ->label('Xtream API URL')
                            ->live()
                            ->helperText('Enter the full url, using <url>:<port> format - without trailing slash (/).')
                            ->prefixIcon('heroicon-m-globe-alt')
                            ->maxLength(255)
                            ->url()
                            ->columnSpan(2)
                            ->required(),
                        Forms\Components\TextInput::make('xtream_config.username')
                            ->label('Xtream API Username')
                            ->live()
                            ->required()
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('xtream_config.password')
                            ->label('Xtream API Password')
                            ->live()
                            ->required()
                            ->columnSpan(1)
                            ->password()
                            ->revealable(),
                    ]),

                Schemas\Components\Fieldset::make('Auth (optional)')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('username')
                            ->label('Username')
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->columnSpan(1)
                            ->password()
                            ->revealable(),
                    ]),

                Forms\Components\Toggle::make('edit_uuid')
                    ->label('View/Update Unique Identifier')
                    ->columnSpanFull()
                    ->inline(false)
                    ->live()
                    ->dehydrated(false)
                    ->default(false),
                Forms\Components\TextInput::make('uuid')
                    ->label('Unique Identifier')
                    ->columnSpanFull()
                    ->rules(function ($record) {
                        return [
                            'required',
                            'min:3',
                            'max:36',
                            Rule::unique('playlist_auths', 'uuid')->ignore($record?->id),
                        ];
                    })
                    ->helperText('Value must be between 3 and 36 characters.')
                    ->hintIcon(
                        'heroicon-m-exclamation-triangle',
                        tooltip: 'Be careful changing this value as this will change the URLs for the Playlist, its EPG, and HDHR.'
                    )
                    ->hidden(fn($get): bool => !$get('edit_uuid'))
                    ->required(),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('alias_of')
                    ->getStateUsing(function ($record) {
                        $playlist = $record->getEffectivePlaylist();
                        if ($playlist) {
                            $type = $playlist instanceof Playlist ? 'Playlist' : 'Custom Playlist';
                            return $playlist->name . ' (' . $type . ')';
                        }
                        return 'N/A';
                    }),
                Tables\Columns\ToggleColumn::make('enabled'),
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
            ->recordActions([
                Actions\EditAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
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
            'index' => Pages\ListPlaylistAliases::route('/'),
            //'create' => Pages\CreatePlaylistAlias::route('/create'),
            //'edit' => Pages\EditPlaylistAlias::route('/{record}/edit'),
        ];
    }
}
