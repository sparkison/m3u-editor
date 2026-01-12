<?php

namespace App\Filament\Resources\Networks\Pages;

use App\Filament\Resources\Networks\NetworkResource;
use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkContent;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class AddMoviesToNetwork extends Page
{
    protected static string $resource = NetworkResource::class;

    protected static string $view = 'filament.resources.networks.pages.add-movies-to-network';

    public ?array $data = [];

    public Network $record;

    public function mount(int|string $record): void
    {
        $this->record = Network::findOrFail($record);

        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Select Movies')
                    ->description('Choose movies (VOD channels) to add to this network')
                    ->schema([
                        Select::make('playlist_id')
                            ->label('Playlist')
                            ->options(function () {
                                return \App\Models\Playlist::where('user_id', Auth::id())
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->live()
                            ->placeholder('All Playlists'),

                        Select::make('group_id')
                            ->label('Group/Genre')
                            ->options(function (callable $get) {
                                $playlistId = $get('playlist_id');

                                $query = \App\Models\Group::query();
                                if ($playlistId) {
                                    $query->where('playlist_id', $playlistId);
                                }

                                return $query->where('user_id', Auth::id())
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->live()
                            ->placeholder('All Groups'),

                        CheckboxList::make('channel_ids')
                            ->label('Movies')
                            ->options(function (callable $get) {
                                $playlistId = $get('playlist_id');
                                $groupId = $get('group_id');

                                $query = Channel::where('user_id', Auth::id())
                                    ->where('is_vod', true);

                                if ($playlistId) {
                                    $query->where('playlist_id', $playlistId);
                                }
                                if ($groupId) {
                                    $query->where('group_id', $groupId);
                                }

                                // Exclude already added movies
                                $existingIds = $this->record->networkContent()
                                    ->where('contentable_type', Channel::class)
                                    ->pluck('contentable_id');

                                return $query->whereNotIn('id', $existingIds)
                                    ->orderBy('name')
                                    ->limit(200) // Limit for performance
                                    ->get()
                                    ->mapWithKeys(fn ($channel) => [
                                        $channel->id => $channel->name,
                                    ]);
                            })
                            ->columns(2)
                            ->searchable()
                            ->bulkToggleable(),
                    ]),
            ])
            ->statePath('data');
    }

    public function addMovies(): void
    {
        $data = $this->form->getState();

        if (empty($data['channel_ids'])) {
            Notification::make()
                ->warning()
                ->title('No Movies Selected')
                ->body('Please select at least one movie to add.')
                ->send();

            return;
        }

        $maxSortOrder = $this->record->networkContent()->max('sort_order') ?? 0;

        foreach ($data['channel_ids'] as $channelId) {
            $maxSortOrder++;

            NetworkContent::create([
                'network_id' => $this->record->id,
                'contentable_type' => Channel::class,
                'contentable_id' => $channelId,
                'sort_order' => $maxSortOrder,
                'weight' => 1,
            ]);
        }

        Notification::make()
            ->success()
            ->title('Movies Added')
            ->body('Added ' . count($data['channel_ids']) . ' movies to the network.')
            ->send();

        $this->redirect(NetworkResource::getUrl('edit', ['record' => $this->record]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Cancel')
                ->url(NetworkResource::getUrl('edit', ['record' => $this->record]))
                ->color('gray'),
        ];
    }

    public function getTitle(): string
    {
        return "Add Movies to {$this->record->name}";
    }

    public function getBreadcrumbs(): array
    {
        return [
            NetworkResource::getUrl() => 'Networks',
            NetworkResource::getUrl('edit', ['record' => $this->record]) => $this->record->name,
            'Add Movies',
        ];
    }
}
