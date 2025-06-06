<?php

namespace App\Filament\Resources\MergedPlaylistResource\RelationManagers;

use App\Enums\Status;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlaylistsRelationManager extends RelationManager
{
    protected static string $relationship = 'playlists';

    public function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('groups_count')
                    ->label('Groups')
                    ->counts('groups')
                    ->sortable(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->sortable(),
                Tables\Columns\TextColumn::make('enabled_channels_count')
                    ->label('Enabled Channels')
                    ->counts('enabled_channels')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn(Status $state) => $state->getColor()),
                Tables\Columns\TextColumn::make('synced')
                    ->label('Last Synced')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name'])
                    ->recordSelectOptionsQuery(
                        fn(Builder $query, $livewire) => $query
                            ->select(['id', 'name'])
                            ->where('user_id', $livewire->ownerRecord->user_id)
                            ->orderBy('name')
                    ),

                // Advanced attach when adding pivot values:
                // Tables\Actions\AttachAction::make()->form(fn(Tables\Actions\AttachAction $action): array => [
                //     $action->getRecordSelect(),
                //     Forms\Components\TextInput::make('title')
                //         ->label('Title')
                //         ->required(),
                // ]),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->icon('heroicon-o-x-circle')
                    ->button()
                    ->hiddenLabel(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make()->color('warning'),
                ]),
            ]);
    }
}
