<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MergedPlaylistResource\Pages;
use App\Filament\Resources\MergedPlaylistResource\RelationManagers;
use App\Forms\Components\PlaylistEpgUrl;
use App\Forms\Components\PlaylistM3uUrl;
use App\Forms\Components\MediaFlowProxyUrl;
use App\Models\MergedPlaylist;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Facades\PlaylistUrlFacade;
use App\Forms\Components\XtreamApiInfo;

class MergedPlaylistResource extends Resource
{
    protected static ?string $model = MergedPlaylist::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', \Illuminate\Support\Facades\Auth::id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-forward';

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function form(Form $form): Form
    {
        $isCreating = $form->getOperation() === 'create';
        return $form
            ->schema(self::getForm($isCreating));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('enabled_live_channels')
                    ->withCount('enabled_vod_channels')
                    ->withCount('enabled_series');
            })
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                // Tables\Columns\TextColumn::make('channels_count')
                //     ->label('Channels')
                //     ->counts('channels')
                //     ->description(fn(MergedPlaylist $record): string => "Enabled: {$record->enabled_channels_count}")
                //     ->toggleable()
                //     ->sortable(),
                Tables\Columns\TextColumn::make('live_channels_count')
                    ->label('Live')
                    ->counts('live_channels')
                    ->description(fn(MergedPlaylist $record): string => "Enabled: {$record->enabled_live_channels_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vod_channels_count')
                    ->label('VOD')
                    ->counts('vod_channels')
                    ->description(fn(MergedPlaylist $record): string => "Enabled: {$record->enabled_vod_channels_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('series_count')
                    ->label('Series')
                    ->counts('series')
                    ->description(fn(MergedPlaylist $record): string => "Enabled: {$record->enabled_series_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('enable_proxy')
                    ->label('Proxy')
                    ->toggleable()
                    ->tooltip('Toggle proxy status')
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
                    Tables\Actions\Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => PlaylistUrlFacade::getUrls($record)['m3u'])
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('Download EPG')
                        ->label('Download EPG')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalHeading('Download EPG')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription('Select the EPG format to download and your download will begin immediately.')
                        ->modalWidth('md')
                        ->modalFooterActions([
                            Tables\Actions\Action::make('uncompressed')
                                ->requiresConfirmation()
                                ->label('Download uncompressed EPG')
                                ->action(fn($record) => redirect(PlaylistUrlFacade::getUrls($record)['epg'])),
                            Tables\Actions\Action::make('compressed')
                                ->requiresConfirmation()
                                ->label('Download gzip EPG')
                                ->action(fn($record) => redirect(PlaylistUrlFacade::getUrls($record)['epg_zip']))
                        ])
                        ->modalSubmitActionLabel('Download EPG'),
                    Tables\Actions\Action::make('HDHomeRun URL')
                        ->label('HDHomeRun Url')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn($record) => PlaylistUrlFacade::getUrls($record)['hdhr'])
                        ->openUrlInNewTab(),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm')
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
            'index' => Pages\ListMergedPlaylists::route('/'),
            // 'create' => Pages\CreateMergedPlaylist::route('/create'),
            'edit' => Pages\EditMergedPlaylist::route('/{record}/edit'),
        ];
    }

    public static function getForm($creating = false): array
    {
        $schema = [
            Forms\Components\TextInput::make('name')
                ->required()
                ->helperText('Enter the name of the playlist. Internal use only.'),
            Forms\Components\TextInput::make('user_agent')
                ->helperText('User agent string to use for making requests.')
                ->default('Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13')
                ->required(),
            Forms\Components\Grid::make()
                ->columnSpanFull()
                ->columns(3)
                ->schema([
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
                                \Illuminate\Validation\Rule::unique('playlists', 'uuid')->ignore($record?->id),
                            ];
                        })
                        ->helperText('Value must be between 3 and 36 characters.')
                        ->hintIcon(
                            'heroicon-m-exclamation-triangle',
                            tooltip: 'Be careful changing this value as this will change the URLs for the Playlist, its EPG, and HDHR.'
                        )
                        ->hidden(fn($get): bool => !$get('edit_uuid'))
                        ->required(),
                ])->hiddenOn('create'),
        ];
        if (PlaylistUrlFacade::mediaFlowProxyEnabled()) {
            $schema[] = Forms\Components\Section::make('MediaFlow Proxy')
                ->description('Your MediaFlow Proxy generated links – to disable clear the MediaFlow Proxy values from the app Settings page.')
                ->collapsible()
                ->collapsed($creating)
                ->headerActions([
                    Forms\Components\Actions\Action::make('mfproxy_git')
                        ->label('GitHub')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->iconPosition('after')
                        ->color('gray')
                        ->size('sm')
                        ->url('https://github.com/mhdzumair/mediaflow-proxy')
                        ->openUrlInNewTab(true),
                    Forms\Components\Actions\Action::make('mfproxy_docs')
                        ->label('Docs')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->iconPosition('after')
                        ->size('sm')
                        ->url(fn($record) => PlaylistUrlFacade::getMediaFlowProxyServerUrl($record) . '/docs')
                        ->openUrlInNewTab(true),
                ])
                ->schema([
                    MediaFlowProxyUrl::make('mediaflow_proxy_url')
                        ->label('Proxied M3U URL')
                        ->columnSpan(2)
                        ->dehydrated(false) // don't save the value in the database
                ])->hiddenOn(['create']);
        }
        $outputScheme = [
            Forms\Components\Section::make('Playlist Output')
                ->description('Determines how the playlist is output')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('auto_channel_increment')
                        ->label('Auto channel number increment')
                        ->columnSpan(1)
                        ->inline(false)
                        ->live()
                        ->default(false)
                        ->helperText('If no channel number is set, output an automatically incrementing number.'),
                    Forms\Components\TextInput::make('channel_start')
                        ->helperText('The starting channel number.')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->hidden(fn(Get $get): bool => !$get('auto_channel_increment'))
                        ->required(),
                ]),
            Forms\Components\Section::make('EPG Output')
                ->description('EPG output options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('dummy_epg')
                        ->label('Enably dummy EPG')
                        ->columnSpan(1)
                        ->live()
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, dummy EPG data will be generated for the next 5 days. Thus, it is possible to assign channels for which no EPG data is available. As program information, the channel name and the set program length are used.'),
                    Forms\Components\Select::make('id_channel_by')
                        ->label('Preferred TVG ID output')
                        ->helperText('How you would like to ID your channels in the EPG.')
                        ->options([
                            'stream_id' => 'TVG ID/Stream ID (default)',
                            'channel_id' => 'Channel Number (recommended for HDHR)',
                            'name' => 'Channel Name',
                            'title' => 'Channel Title',
                        ])
                        ->required()
                        ->default('stream_id') // Default to stream_id
                        ->columnSpan(1),
                    Forms\Components\TextInput::make('dummy_epg_length')
                        ->label('Dummy program length (in minutes)')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(120)
                        ->hidden(fn(Get $get): bool => !$get('dummy_epg'))
                        ->required(),
                ]),
            Forms\Components\Section::make('Streaming Output')
                ->description('Output processing options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('enable_proxy')
                        ->label('Enable Proxy')
                        ->hint(fn(Get $get): string => $get('enable_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn(Get $get): string => !$get('enable_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->columnSpanFull()
                        ->live()
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, channel urls will be proxied through m3u editor and streamed via ffmpeg (m3u editor will act as your client, playing the channels directly and sending the content to your client).'),
                    Forms\Components\TextInput::make('streams')
                        ->label('HDHR Streams')
                        ->helperText('Number of streams available for HDHR service (if using).')
                        ->columnSpan(1)
                        ->rules(['min:0'])
                        ->type('number')
                        ->default(1) // Default to 1 stream
                        ->required()
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                    Forms\Components\Select::make('proxy_options.output')
                        ->label('Proxy Output Format')
                        ->required()
                        ->columnSpan(1)
                        ->options([
                            'ts' => 'MPEG-TS (.ts)',
                            'hls' => 'HLS (.m3u8)',
                        ])
                        ->default('ts')->helperText('NOTE: Only HLS streaming supports multiple clients per stream.')
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),

                ])
        ];
        return [
            Forms\Components\Grid::make()
                ->hiddenOn(['edit']) // hide this field on the edit form
                ->schema([
                    ...$schema,
                    ...$outputScheme,
                    Forms\Components\CheckboxList::make('failover_playlist_ids')
                        ->label('Failover Playlists')
                        ->relationship('playlists', 'name')
                        ->helperText('Select playlists to use for failover.')
                        ->afterStateUpdated(function ($state, $record) {
                            if (!$record) return;

                            $record->playlists()->updateExistingPivot($record->playlists, ['is_failover' => false]);
                            $record->playlists()->updateExistingPivot($state, ['is_failover' => true]);
                        })
                        ->dehydrated(false)
                        ->default(function ($record) {
                            if ($record) {
                                return $record->playlists()->where('is_failover', true)->pluck('playlists.id')->toArray();
                            }
                            return [];
                        })
                ])
                ->columns(2),
            Forms\Components\Grid::make()
                ->hiddenOn(['create']) // hide this field on the create form
                ->columns(5)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Tabs::make('tabs')
                        ->columnSpan(3)
                        ->persistTabInQueryString()
                        ->tabs([
                            Forms\Components\Tabs\Tab::make('General')
                                ->columns(2)
                                ->schema($schema),
                            Forms\Components\Tabs\Tab::make('Output')
                                ->columns(2)
                                ->schema($outputScheme),
                        ]),
                    Forms\Components\Grid::make()
                        ->columnSpan(2)
                        ->columns(2)
                        ->schema([

                            Forms\Components\Section::make('Auth')
                                ->compact()
                                ->description('Add and manage authentication.')
                                ->icon('heroicon-m-key')
                                ->collapsible()
                                ->collapsed(true)
                                ->schema([
                                    Forms\Components\Select::make('assigned_auth_ids')
                                        ->label('Assigned Auths')
                                        ->multiple()
                                        ->options(function ($record) {
                                            $options = [];

                                            // Get currently assigned auths for this playlist
                                            if ($record) {
                                                $currentAuths = $record->playlistAuths()->get();
                                                foreach ($currentAuths as $auth) {
                                                    $options[$auth->id] = $auth->name . ' (currently assigned)';
                                                }
                                            }

                                            // Get unassigned auths
                                            $unassignedAuths = \App\Models\PlaylistAuth::where('user_id', \Illuminate\Support\Facades\Auth::id())
                                                ->whereDoesntHave('assignedPlaylist')
                                                ->get();

                                            foreach ($unassignedAuths as $auth) {
                                                $options[$auth->id] = $auth->name;
                                            }

                                            return $options;
                                        })
                                        ->searchable()
                                        ->nullable()
                                        ->placeholder('Select auths or leave empty')
                                        ->default(function ($record) {
                                            if ($record) {
                                                return $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                                            }
                                            return [];
                                        })
                                        ->afterStateHydrated(function ($component, $state, $record) {
                                            if ($record) {
                                                $currentAuthIds = $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                                                $component->state($currentAuthIds);
                                            }
                                        })
                                        ->helperText('Only unassigned auths are available. Each auth can only be assigned to one playlist at a time.')
                                        ->afterStateUpdated(function ($state, $record) {
                                            if (!$record) return;

                                            $currentAuthIds = $record->playlistAuths()->pluck('playlist_auths.id')->toArray();
                                            $newAuthIds = $state ? (is_array($state) ? $state : [$state]) : [];

                                            // Find auths to remove (currently assigned but not in new selection)
                                            $authsToRemove = array_diff($currentAuthIds, $newAuthIds);
                                            foreach ($authsToRemove as $authId) {
                                                $auth = \App\Models\PlaylistAuth::find($authId);
                                                if ($auth) {
                                                    $auth->clearAssignment();
                                                }
                                            }

                                            // Find auths to add (in new selection but not currently assigned)
                                            $authsToAdd = array_diff($newAuthIds, $currentAuthIds);
                                            foreach ($authsToAdd as $authId) {
                                                $auth = \App\Models\PlaylistAuth::find($authId);
                                                if ($auth) {
                                                    $auth->assignTo($record);
                                                }
                                            }
                                        })
                                        ->dehydrated(false), // Don't save this field directly
                                ]),
                            Forms\Components\Section::make('Xtream API')
                                ->compact()
                                ->description('Xtream API connection details.')
                                ->icon('heroicon-m-bolt')
                                ->collapsible()
                                ->columnSpan(2)
                                ->collapsed(true)
                                ->schema([
                                    XtreamApiInfo::make('xtream_api_info')
                                        ->label('Xtream API Info')
                                        ->columnSpan(2)
                                        ->dehydrated(false), // don't save the value in the database
                                ]),
                            Forms\Components\Section::make('Links')
                                ->compact()
                                ->icon('heroicon-m-link')
                                ->collapsible()
                                ->persistCollapsed()
                                ->collapsed(true)
                                ->schema([
                                    Forms\Components\Toggle::make('short_urls_enabled')
                                        ->label('Use Short URLs')
                                        ->helperText('When enabled, short URLs will be used for the playlist links. Save changes to generate the short URLs (or remove them).')
                                        ->columnSpan(2)
                                        ->inline(false)
                                        ->default(false),
                                    PlaylistM3uUrl::make('m3u_url')
                                        ->label('M3U URL')
                                        ->columnSpan(2)
                                        ->dehydrated(false), // don't save the value in the database
                                    PlaylistEpgUrl::make('epg_url')
                                        ->label('EPG URL')
                                        ->columnSpan(2)
                                        ->dehydrated(false) // don't save the value in the database
                                ])
                        ])
                ])

        ];
    }
}
