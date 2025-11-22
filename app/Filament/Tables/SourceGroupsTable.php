<?php

namespace App\Filament\Tables;

use App\Models\SourceGroup;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SourceGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn(): Builder => SourceGroup::query())
            ->modifyQueryUsing(function (Builder $query) use ($table): Builder {
                $arguments = $table->getArguments();

                if ($playlistId = $arguments['playlist_id'] ?? null) {
                    $query->where('playlist_id', $playlistId);
                }

                return $query;
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Group Name')
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    //
                ]),
            ]);
    }
}
