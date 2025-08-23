<?php

namespace App\Filament\Resources\EpgChannels;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\EditAction;
use Filament\Tables\Enums\RecordActionsPosition;
use App\Filament\Resources\EpgChannels\Pages\ListEpgChannels;
use Filament\Forms\Components\TextInput;
use App\Filament\Resources\EpgChannelResource\Pages;
use App\Filament\Resources\EpgChannelResource\RelationManagers;
use App\Models\EpgChannel;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EpgChannelResource extends Resource
{
    protected static ?string $model = EpgChannel::class;

    //    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'display_name'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $label = 'EPG Channel';
    protected static ?string $pluralLabel = 'EPG Channels';

    protected static string | \UnitEnum | null $navigationGroup = 'EPG';

    public static function getNavigationSort(): ?int
    {
        return 5;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return self::setupTable($table);
    }

    public static function setupTable(Table $table, $relationId = null): Table
    {
        return $table
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->deferLoading()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                ImageColumn::make('icon')
                    ->checkFileExistence(false)
                    ->toggleable()
                    ->height(30)
                    ->width('auto'),
                TextInputColumn::make('display_name')
                    ->sortable()
                    ->tooltip(fn($record) => $record->display_name)
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('name')
                    ->limit(40)
                    ->sortable()
                    ->searchable(),
                TextColumn::make('lang')
                    ->sortable()
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('channel_id')
                    ->sortable()
                    ->toggleable()
                    ->searchable(),
                // WARNING! Slows table load quite a bit...
                // Tables\Columns\TextColumn::make('programmes_count')
                //     ->label('Programs')
                //     ->counts('programmes')
                //     ->sortable(),
                TextColumn::make('epg.name')
                    ->sortable()
                    ->numeric()
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('epg')
                    ->relationship('epg', 'name')
                    ->hidden(fn() => $relationId)
                    ->multiple()
                    ->preload()
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                // Tables\Actions\BulkActionGroup::make([
                //     Tables\Actions\DeleteBulkAction::make(),
                // ]),
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
            'index' => ListEpgChannels::route('/'),
            // 'create' => Pages\CreateEpgChannel::route('/create'),
            // 'edit' => Pages\EditEpgChannel::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            // Forms\Components\TextInput::make('name')
            //     ->required()
            //     ->maxLength(255),
            TextInput::make('icon')
                ->prefixIcon('heroicon-m-globe-alt')
                ->url(),
            TextInput::make('display_name')
                ->maxLength(255),
            // Forms\Components\TextInput::make('lang')
            //     ->maxLength(255),
            // Forms\Components\TextInput::make('channel_id')
            //     ->maxLength(255),
            // Forms\Components\Select::make('epg_id')
            //     ->relationship('epg', 'name')
            //     ->required(),
        ];
    }
}
