<?php

namespace App\Filament\Resources\PlaylistResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SyncStatusesRelationManager extends RelationManager
{
    protected static string $relationship = 'syncStatuses';

    protected static ?string $title = 'Sync logs';

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Playlist Name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('removed_groups_count')
                    ->label('Removed Groups')
                    ->counts('removedGroups')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('added_groups_count')
                    ->label('Added Groups')
                    ->counts('addedGroups')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('removed_channels_count')
                    ->label('Removed Channels')
                    ->counts('removedChannels')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('added_channels_count')
                    ->label('Added Channels')
                    ->counts('addedChannels')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->slideOver(),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel()
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
