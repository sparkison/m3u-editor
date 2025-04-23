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
        return ['name', 'username'];
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
                ->default(true)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\Select::make('event')
                ->required()
                ->options([
                    'synced' => 'Synced',
                    'created' => 'Created',
                    'updated' => 'Updated',
                    'deleted' => 'Deleted',
                ]),
            Forms\Components\TextInput::make('metadata.path')
                ->label('Webhook URL or Local file path')
                ->columnSpan(2)
                ->prefixIcon('heroicon-m-globe-alt')
                ->placeholder(route('webhook.test.get'))
                ->helperText('Enter the URL or process to call. If this is a local file, you can enter a full or relative path.')
                ->required()
                ->rules([new CheckIfUrlOrLocalPath()])
                ->maxLength(255),
            Forms\Components\Fieldset::make('Request Options')
                ->schema([
                    Forms\Components\ToggleButtons::make('metadata.get')
                        ->label('Request type')
                        ->grouped()
                        ->options([
                            false => 'GET',
                            true => 'POST',
                        ])
                        ->default(false),
                    Forms\Components\CheckboxList::make('metadata.post_attributes')
                        ->label('Request variables')
                        ->options([
                            'name' => 'Name',
                            'uuid' => 'UUID',
                            'url' => 'URL',
                        ])->helperText('If using a webhook URL, these attributes can be (optionally) sent as GET or POST data. If using a local script, you can safely ignore this option.')
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
