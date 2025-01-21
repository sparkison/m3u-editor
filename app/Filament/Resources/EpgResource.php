<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EpgResource\Pages;
use App\Filament\Resources\EpgResource\RelationManagers;
use App\Models\Epg;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EpgResource extends Resource
{
    protected static ?string $model = Epg::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $label = 'EPGs';

    protected static ?string $navigationGroup = 'EPG';

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
                    ->searchable(),
                Tables\Columns\TextColumn::make('url')
                    ->searchable(),
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
            'index' => Pages\ListEpgs::route('/'),
            // 'create' => Pages\CreateEpg::route('/create'),
            // 'edit' => Pages\EditEpg::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->required()
                ->helperText('Enter the name of the EPG. Internal use only.')
                ->maxLength(255),
            Forms\Components\TextInput::make('url')
                ->required()
                ->label('XMLTV URL')
                ->url()
                ->prefixIcon('heroicon-m-globe-alt')
                ->required()
                ->helperText('Enter the URL of the XMLTV guide data. If changing URL, the guide data will be re-imported. Use with caution as this could lead to data loss if the new guide differs from the old one.')
                ->maxLength(255),
        ];
    }
}
