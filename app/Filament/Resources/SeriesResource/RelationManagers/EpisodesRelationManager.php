<?php

namespace App\Filament\Resources\SeriesResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EpisodesRelationManager extends RelationManager
{
    protected static string $relationship = 'episodes';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['season']);
            })
            ->defaultGroup('season')
            ->defaultSort('episode_num', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('episode_num')
                    ->label('Episode')
                    ->sortable(),
                Tables\Columns\TextColumn::make('season.name')
                    ->label('Season Name')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->actions([
                // Tables\Actions\ViewAction::make()->hiddenLabel()->button(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                //
            ]);
    }
}
