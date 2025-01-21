<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EpgChannelResource\Pages;
use App\Filament\Resources\EpgChannelResource\RelationManagers;
use App\Models\EpgChannel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EpgChannelResource extends Resource
{
    protected static ?string $model = EpgChannel::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    public static function getNavigationSort(): ?int
    {
        return 5;
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
                    ->searchable(),
                Tables\Columns\TextColumn::make('display_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('lang')
                    ->searchable(),
                Tables\Columns\TextColumn::make('channel_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('epg.name')
                    ->numeric()
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
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListEpgChannels::route('/'),
            // 'create' => Pages\CreateEpgChannel::route('/create'),
            // 'edit' => Pages\EditEpgChannel::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('display_name')
                ->maxLength(255),
            Forms\Components\TextInput::make('lang')
                ->maxLength(255),
            Forms\Components\TextInput::make('channel_id')
                ->maxLength(255),
            Forms\Components\Select::make('epg_id')
                ->relationship('epg', 'name')
                ->required(),
            Forms\Components\Textarea::make('programmes')
                ->columnSpanFull(),
        ];
    }
}
