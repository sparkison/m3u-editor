<?php

namespace App\Filament\Resources\MergedEpgs\RelationManagers;

use App\Enums\Status;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SourceEpgsRelationManager extends RelationManager
{
    protected static string $relationship = 'sourceEpgs';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn (Status $state) => $state->getColor()),
                TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->sortable(),
                TextColumn::make('synced')
                    ->label('Last Synced')
                    ->since()
                    ->sortable(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name'])
                    ->recordSelectOptionsQuery(
                        fn (Builder $query, $livewire) => $query
                            ->where('user_id', $livewire->ownerRecord->user_id)
                            ->where('is_merged', false)
                            ->where('id', '!=', $livewire->ownerRecord->id)
                            ->orderBy('name')
                    ),
            ])
            ->recordActions([
                DetachAction::make()
                    ->icon('heroicon-o-x-circle')
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()->color('warning'),
                ]),
            ]);
    }
}
