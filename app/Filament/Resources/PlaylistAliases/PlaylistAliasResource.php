<?php

namespace App\Filament\Resources\PlaylistAliases;

use App\Facades\PlaylistFacade;
use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use App\Services\EpgCacheService;
use Carbon\Carbon;
use Exception;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
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
            ->components(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['playlist', 'customPlaylist']);
            })
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
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
                    })
                    ->url(function ($record) {
                        $playlist = $record->getEffectivePlaylist();
                        if ($playlist instanceof Playlist) {
                            return PlaylistResource::getUrl('edit', ['record' => $playlist->id]);
                        } elseif ($playlist instanceof CustomPlaylist) {
                            return CustomPlaylistResource::getUrl('edit', ['record' => $playlist->id]);
                        }
                        return null;
                    }),
                Tables\Columns\TextColumn::make('user_info')
                    ->label('Provider Streams')
                    ->getStateUsing(function ($record) {
                        try {
                            if ($record->xtream_status['user_info'] ?? false) {
                                return $record->xtream_status['user_info']['max_connections'];
                            }
                        } catch (Exception $e) {
                        }
                        return 'N/A';
                    })
                    ->description(fn($record): string => "Active: " . $record->xtream_status['user_info']['active_cons'] ?? 0)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('available_streams')
                    ->label('Proxy Streams')
                    ->toggleable()
                    ->getStateUsing(function (PlaylistAlias $record) {
                        $effectivePlaylist = $record->getEffectivePlaylist();
                        return $effectivePlaylist ? $effectivePlaylist->available_streams : 0;
                    })
                    ->formatStateUsing(fn(int $state): string => $state === 0 ? '∞' : (string)$state)
                    ->tooltip('Total streams available for this playlist (∞ indicates no limit)')
                    ->description(fn(PlaylistAlias $record): string => "Active: " . (int) Redis::get("active_streams:{$record->uuid}") ?? 0),
                Tables\Columns\ToggleColumn::make('enabled'),
                Tables\Columns\TextColumn::make('exp_date')
                    ->label('Expiry Date')
                    ->getStateUsing(function ($record) {
                        try {
                            if ($record->xtream_status['user_info']['exp_date'] ?? false) {
                                $expires = Carbon::createFromTimestamp($record->xtream_status['user_info']['exp_date']);
                                return $expires->toDayDateTimeString();
                            }
                        } catch (Exception $e) {
                        }
                        return 'N/A';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Actions\ActionGroup::make([
                    Actions\Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => PlaylistFacade::getUrls($record)['m3u'])
                        ->openUrlInNewTab(),
                    EpgCacheService::getEpgTableAction(),
                    Actions\Action::make('HDHomeRun URL')
                        ->label('HDHomeRun URL')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn($record) => PlaylistFacade::getUrls($record)['hdhr'])
                        ->openUrlInNewTab(),
                    Actions\Action::make('Public URL')
                        ->label('Public URL')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn($record) => '/playlist/v/' . $record->uuid)
                        ->openUrlInNewTab(),
                    Actions\Action::make('reset_active_count')
                        ->label('Reset active count')
                        ->icon('heroicon-o-numbered-list')
                        ->color('warning')
                        ->action(fn($record) => Redis::set("active_streams:{$record->uuid}", 0))->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Active stream count reset')
                                ->body('Playlist active stream count has been reset.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-numbered-list')
                        ->modalDescription('Reset playlist active streams count. Proceed with caution as this could lead to an incorrect count if there are streams currently running.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Actions\DeleteAction::make()
                ])->button()->hiddenLabel()->size('sm'),
                Actions\EditAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\BulkAction::make('reset_active_count')
                        ->label('Reset active count')
                        ->icon('heroicon-o-numbered-list')
                        ->color('warning')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                Redis::set("active_streams:{$record->uuid}", 0);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Active stream count reset')
                                ->body('Active stream count has been reset for the selected Playlists.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-numbered-list')
                        ->modalDescription('Reset active streams count for the selected Playlists. Proceed with caution as this could lead to an incorrect count if there are streams currently running.')
                        ->modalSubmitActionLabel('Yes, reset now'),
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

    public static function getForm(): array
    {
        return [
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
                        ->options(fn() => Playlist::where('user_id', Auth::id())->pluck('name', 'id'))
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
                        ->options(fn() => CustomPlaylist::where('user_id', Auth::id())->pluck('name', 'id'))
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
                ->default(false)
                ->hiddenOn('create'),
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
        ];
    }
}
