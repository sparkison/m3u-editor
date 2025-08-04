<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostProcessResource\Pages;
use App\Filament\Resources\PostProcessResource\RelationManagers;
use App\Models\PostProcess;
use App\Rules\CheckIfUrlOrLocalPath;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PostProcessResource extends Resource
{
    protected static ?string $model = PostProcess::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $label = 'Post Process';
    protected static ?string $pluralLabel = 'Post Processing';

    protected static ?string $navigationGroup = 'Tools';

    public static function getNavigationSort(): ?int
    {
        return 7;
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
                // Tables\Columns\TextInputColumn::make('name')
                //     ->label('Name')
                //     ->rules(['min:0', 'max:255'])
                //     ->required()
                //     ->searchable()
                //     ->toggleable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('event')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('processes_count')
                    ->label('Items')
                    ->counts('processes')
                    ->toggleable()
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
                ])->button()->hiddenLabel()->size('sm'),
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
            RelationManagers\ProcessesRelationManager::class,
            RelationManagers\LogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPostProcesses::route('/'),
            // 'create' => Pages\CreatePostProcess::route('/create'),
            'edit' => Pages\EditPostProcess::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        $schema = [
            Forms\Components\Toggle::make('enabled')
                ->default(true)->helperText('Enable this post process'),
            Forms\Components\Toggle::make('metadata.send_failed')
                ->label('Process failed')
                ->default(false)->helperText('Process on failed syncs too (default is only successful syncs).'),
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('event')
                ->required()
                ->options([
                    'synced' => 'Synced',
                    'created' => 'Created',
                    // 'updated' => 'Updated', // Can lead to a lot of calls! Updates are called during the sync process.
                    'deleted' => 'Deleted',
                ])->default('synced'),
            Forms\Components\ToggleButtons::make('metadata.local')
                ->label('Type')
                ->grouped()
                ->required()
                ->options([
                    false => 'URL',
                    true => 'Local file',
                ])
                ->icons([
                    false => 'heroicon-s-link',
                    true => 'heroicon-s-document',
                ])
                ->live()
                ->default(false),
            Forms\Components\TextInput::make('metadata.path')
                ->label(fn(Get $get) => $get('metadata.local') ? 'Path' : 'URL')
                ->columnSpan(2)
                ->prefixIcon(fn(Get $get) => $get('metadata.local') ? 'heroicon-o-document' : 'heroicon-o-globe-alt')
                ->placeholder(fn(Get $get) => $get('metadata.local') ? '/var/www/html/custom_script' : route('webhook.test.get'))
                ->helperText(fn(Get $get) => $get('metadata.local') ? 'Path to local file' : 'Webhook URL')
                ->required()
                ->rules(fn(Get $get) => [
                    new CheckIfUrlOrLocalPath(
                        urlOnly: !$get('metadata.local'),
                        localOnly: $get('metadata.local'),
                    ),
                ])
                ->maxLength(255),
            Forms\Components\Fieldset::make('Request Options')
                ->schema([
                    Forms\Components\ToggleButtons::make('metadata.post')
                        ->label('Request type')
                        ->grouped()
                        ->required()
                        ->options([
                            false => 'GET',
                            true => 'POST',
                        ])
                        ->default(false)
                        ->helperText('Send as GET or POST request.'),
                    Forms\Components\Repeater::make('metadata.post_vars')
                        ->label('GET/POST variables')
                        ->schema([
                            Forms\Components\TextInput::make('variable_name')
                                ->label('Variable name')
                                ->placeholder('variable_name')
                                ->helperText('Name of the variable to send as GET/POST variable to your webhook URL.')
                                ->datalist([
                                    'name',
                                    'uuid',
                                    'url',
                                ])
                                ->alphaDash()
                                ->ascii()
                                ->required(),
                            Forms\Components\Select::make('value')
                                ->label('Value')
                                ->required()
                                ->options([
                                    'id' => 'ID',
                                    'uuid' => 'UUID',
                                    'name' => 'Name',
                                    'url' => 'URL',
                                    'status' => 'Status',
                                    'synctime' => 'Sync time',
                                    'added_groups' => '# Groups added (Playlist only)',
                                    'removed_groups' => '# Groups removed (Playlist only)',
                                    'added_channels' => '# Channels added (Playlist only)',
                                    'removed_channels' => '# Channels removed (Playlist only)',
                                ])->helperText('Value to use for this variable.'),
                        ])
                        ->columns(2)
                        ->columnSpanFull()
                        ->addActionLabel('Add GET/POST variable'),
                ])->hidden(fn(Get $get) => !! $get('metadata.local')),
            Forms\Components\Fieldset::make('Script Options')
                ->schema([
                    Forms\Components\Repeater::make('metadata.script_vars')
                        ->label('Export variables')
                        ->schema([
                            Forms\Components\TextInput::make('export_name')
                                ->label('Export name')
                                ->placeholder('VARIABLE_NAME')
                                ->helperText('Name of the variable to export. Example: VARIABLE_NAME can be used as $VARIABLE_NAME in your script.')
                                ->datalist([
                                    'NAME',
                                    'UUID',
                                    'URL',
                                    'M3U_NAME',
                                    'M3U_UUID',
                                    'M3U_URL',
                                ])
                                ->alphaDash()
                                ->ascii()
                                ->required(),
                            Forms\Components\Select::make('value')
                                ->label('Value')
                                ->required()
                                ->options([
                                    'id' => 'ID',
                                    'uuid' => 'UUID',
                                    'name' => 'Name',
                                    'url' => 'URL',
                                    'status' => 'Status',
                                    'synctime' => 'Sync time',
                                    'added_groups' => '# Groups added (Playlist only)',
                                    'removed_groups' => '# Groups removed (Playlist only)',
                                    'added_channels' => '# Channels added (Playlist only)',
                                    'removed_channels' => '# Channels removed (Playlist only)',
                                ])->helperText('Value to use for this variable.'),
                        ])
                        ->columns(2)
                        ->columnSpanFull()
                        ->addActionLabel('Add named export'),
                ])->hidden(fn(Get $get) => ! $get('metadata.local')),
            Forms\Components\Fieldset::make('Conditional Settings')
                ->schema([
                    Forms\Components\Repeater::make('conditions')
                        ->label('Conditions')
                        ->schema([
                            Forms\Components\Select::make('field')
                                ->label('Field')
                                ->required()
                                ->options([
                                    'id' => 'ID',
                                    'uuid' => 'UUID',
                                    'name' => 'Name',
                                    'url' => 'URL',
                                    'status' => 'Status',
                                    'synctime' => 'Sync time',
                                    'added_groups' => '# Groups added (Playlist only)',
                                    'removed_groups' => '# Groups removed (Playlist only)',
                                    'added_channels' => '# Channels added (Playlist only)',
                                    'removed_channels' => '# Channels removed (Playlist only)',
                                ])
                                ->helperText('Field to check condition against.'),
                            Forms\Components\Select::make('operator')
                                ->label('Condition')
                                ->required()
                                ->options([
                                    'equals' => 'Equals',
                                    'not_equals' => 'Not equals',
                                    'greater_than' => 'Greater than',
                                    'less_than' => 'Less than',
                                    'greater_than_or_equal' => 'Greater than or equal',
                                    'less_than_or_equal' => 'Less than or equal',
                                    'contains' => 'Contains',
                                    'not_contains' => 'Does not contain',
                                    'starts_with' => 'Starts with',
                                    'ends_with' => 'Ends with',
                                    'is_true' => 'Is true',
                                    'is_false' => 'Is false',
                                    'is_empty' => 'Is empty',
                                    'is_not_empty' => 'Is not empty',
                                ])
                                ->helperText('Condition to check.')
                                ->live(),
                            Forms\Components\TextInput::make('value')
                                ->label('Value')
                                ->helperText('Value to compare against (not needed for true/false/empty conditions).')
                                ->hidden(fn(Get $get) => in_array($get('operator'), ['is_true', 'is_false', 'is_empty', 'is_not_empty']))
                                ->required(fn(Get $get) => !in_array($get('operator'), ['is_true', 'is_false', 'is_empty', 'is_not_empty'])),
                        ])
                        ->columns(3)
                        ->columnSpanFull()
                        ->addActionLabel('Add condition')
                        ->helperText('Add conditions that must be met for this post process to execute. All conditions must be true for execution.'),
                ]),
        ];
        return [
            Forms\Components\Grid::make()
                ->hiddenOn(['edit']) // hide this field on the edit form
                ->schema($schema)
                ->columns(2),
            Forms\Components\Section::make('Post Process')
                ->hiddenOn(['create']) // hide this field on the create form
                ->schema($schema)
                ->columns(2),
        ];
    }
}
