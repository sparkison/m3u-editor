<?php

namespace App\Filament\Resources\Networks\RelationManagers;

use App\Models\NetworkContent;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class NetworkContentRelationManager extends RelationManager
{
    protected static string $relationship = 'networkContent';

    protected static ?string $title = 'Content';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sort_order')
                    ->label('Sort Order')
                    ->numeric()
                    ->default(0)
                    ->required(),

                TextInput::make('weight')
                    ->label('Weight (for shuffle)')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->helperText('Higher weight = more likely to appear when shuffling'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->width('60px'),

                TextColumn::make('contentable_type')
                    ->label('Type')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'App\\Models\\Episode' => 'Episode',
                        'App\\Models\\Channel' => 'Movie',
                        default => 'Unknown',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'App\\Models\\Episode' => 'info',
                        'App\\Models\\Channel' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('title')
                    ->label('Title')
                    ->getStateUsing(fn (NetworkContent $record): string => $record->title)
                    ->searchable(false)
                    ->wrap(),

                TextColumn::make('duration')
                    ->label('Duration')
                    ->getStateUsing(function (NetworkContent $record): string {
                        $seconds = $record->duration_seconds;
                        if ($seconds <= 0) {
                            return 'Unknown';
                        }
                        $hours = floor($seconds / 3600);
                        $minutes = floor(($seconds % 3600) / 60);

                        return $hours > 0
                            ? sprintf('%dh %dm', $hours, $minutes)
                            : sprintf('%dm', $minutes);
                    }),

                TextColumn::make('weight')
                    ->label('Weight')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('addEpisodes')
                    ->label('Add Episodes')
                    ->icon('heroicon-o-film')
                    ->color('info')
                    ->url(fn ($livewire) => route('filament.admin.resources.networks.add-episodes', [
                        'record' => $livewire->ownerRecord->id,
                    ])),

                Action::make('addMovies')
                    ->label('Add Movies')
                    ->icon('heroicon-o-video-camera')
                    ->color('success')
                    ->url(fn ($livewire) => route('filament.admin.resources.networks.add-movies', [
                        'record' => $livewire->ownerRecord->id,
                    ])),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->icon('heroicon-o-x-circle')
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->color('warning'),
                ]),
            ]);
    }
}
