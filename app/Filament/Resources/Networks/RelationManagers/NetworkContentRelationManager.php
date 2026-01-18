<?php

namespace App\Filament\Resources\Networks\RelationManagers;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Network;
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

    protected static ?string $recordTitleAttribute = 'id';

    /**
     * Get the playlist ID associated with this network's media server integration.
     */
    protected function getMediaServerPlaylistId(): ?int
    {
        /** @var Network $network */
        $network = $this->getOwnerRecord();

        return $network->mediaServerIntegration?->playlist_id;
    }

    /**
     * Get the media server integration name for display.
     */
    protected function getMediaServerName(): string
    {
        /** @var Network $network */
        $network = $this->getOwnerRecord();

        return $network->mediaServerIntegration?->name ?? 'Unknown';
    }

    public function table(Table $table): Table
    {
        $playlistId = $this->getMediaServerPlaylistId();
        $mediaServerName = $this->getMediaServerName();

        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns($this->getColumns())
            ->headerActions($this->getHeaderActions($playlistId, $mediaServerName))
            ->emptyStateHeading(fn () => $playlistId
                ? 'No content added yet'
                : 'No media server linked')
            ->emptyStateDescription(fn () => $playlistId
                ? 'Add episodes or movies from your media server to this network.'
                : 'This network must be linked to a media server to add content.')
            ->emptyStateIcon(fn () => $playlistId
                ? 'heroicon-o-film'
                : 'heroicon-o-exclamation-triangle')
            ->recordActions($this->getRecordActions(), position: RecordActionsPosition::BeforeCells)
            ->toolbarActions($this->getToolbarActions());
    }

    /**
     * Return the columns used in the table.
     *
     * @return array<int, \Filament\Tables\Columns\Column>
     */
    protected function getColumns(): array
    {
        return [
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
                ->wrap()
                ->searchable(false),

            TextColumn::make('duration')
                ->label('Duration')
                ->getStateUsing(function (NetworkContent $record): string {
                    $seconds = $record->duration_seconds;
                    if ($seconds <= 0) {
                        return '~30m (default)';
                    }
                    $hours = floor($seconds / 3600);
                    $minutes = floor(($seconds % 3600) / 60);

                    return $hours > 0
                        ? sprintf('%dh %dm', $hours, $minutes)
                        : sprintf('%dm', $minutes);
                }),

            TextColumn::make('weight')
                ->label('Weight')
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    /**
     * Return header actions for the table.
     */
    protected function getHeaderActions(?int $playlistId, string $mediaServerName): array
    {
        return [
            Action::make('addEpisodes')
                ->label('Add Episodes')
                ->icon('heroicon-o-film')
                ->color('info')
                ->visible(fn () => $playlistId !== null)
                ->modalWidth('7xl')
                ->modalContent(fn () => view('filament.networks.modals.episode-picker', ['network' => $this->getOwnerRecord()])),

            Action::make('addMovies')
                ->label('Add Movies')
                ->icon('heroicon-o-video-camera')
                ->color('success')
                ->visible(fn () => $playlistId !== null)
                ->modalWidth('7xl')
                ->modalContent(fn () => view('filament.networks.modals.vod-picker', ['network' => $this->getOwnerRecord()])),
        ];
    }

    /**
     * Get record actions for the table.
     *
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getRecordActions(): array
    {
        return [
            DeleteAction::make()
                ->icon('heroicon-o-x-circle')
                ->button()
                ->hiddenLabel(),
        ];
    }

    /**
     * Get toolbar actions for the table.
     *
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getToolbarActions(): array
    {
        return [
            BulkActionGroup::make([
                DeleteBulkAction::make(),
            ]),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
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
}
