<?php

namespace App\Livewire\Filament\Networks;

use App\Models\Episode;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\Series;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class EpisodePicker extends Component
{
    use WithPagination;

    public Network $network;

    public ?int $seriesId = null;

    public string $search = '';

    public int $perPage = 25;

    public array $selected = [];

    protected $queryString = [
        'seriesId' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    protected $listeners = [
        'networkContentAdded' => '$refresh',
    ];

    public function mount(Network $network): void
    {
        // Eager load the relationship to ensure playlist_id is available
        $this->network = $network->load('mediaServerIntegration');
        $this->resetPage();
    }

    protected function getPlaylistId(): ?int
    {
        return $this->network->mediaServerIntegration?->playlist_id;
    }

    public function getSeriesOptionsProperty(): array
    {
        $playlistId = $this->getPlaylistId();

        if (! $playlistId) {
            return [];
        }

        return Series::where('playlist_id', $playlistId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function updatedSeriesId(): void
    {
        $this->selected = [];
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    protected function getExistingEpisodeIds(): array
    {
        return $this->network->networkContent()
            ->where('contentable_type', Episode::class)
            ->pluck('contentable_id')
            ->map(fn ($i) => (int) $i)
            ->all();
    }

    protected function query()
    {
        $query = Episode::query()->with('series');

        if ($this->seriesId) {
            $query->where('series_id', $this->seriesId);
        } else {
            // If no series selected, make it explicit (avoid returning entire DB)
            $query->whereNull('id');
        }

        $existing = $this->getExistingEpisodeIds();
        if (! empty($existing)) {
            $query->whereNotIn('id', $existing);
        }

        if ($this->search !== '') {
            $q = Str::lower($this->search);

            $query->where(function ($sub) use ($q) {
                $sub->whereRaw('LOWER(title) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('CAST(season AS TEXT) LIKE ?', ["%{$q}%"])
                    ->orWhereRaw('CAST(episode_num AS TEXT) LIKE ?', ["%{$q}%"]);
            });
        }

        return $query->orderBy('season')->orderBy('episode_num');
    }

    public function getEpisodesProperty()
    {
        return $this->query()->paginate($this->perPage);
    }

    public function toggleSelectAllOnPage(): void
    {
        $ids = $this->episodes->pluck('id')->map(fn ($i) => (int) $i)->all();

        $allSelected = Arr::every($ids, fn ($id) => in_array($id, $this->selected, true));

        if ($allSelected) {
            $this->selected = array_values(array_diff($this->selected, $ids));

            return;
        }

        $this->selected = array_values(array_unique(array_merge($this->selected, $ids)));
    }

    public function addSelected(): void
    {
        $selected = array_map('intval', $this->selected);

        if (empty($selected)) {
            Notification::make()
                ->danger()
                ->title('No episodes selected')
                ->send();

            return;
        }

        // Re-validate server-side that these episodes belong to the playlist and are not already added
        $valid = Episode::whereIn('id', $selected)
            ->where('playlist_id', $this->network->mediaServerIntegration?->playlist_id)
            ->pluck('id')
            ->map(fn ($i) => (int) $i)
            ->all();

        $existing = $this->getExistingEpisodeIds();

        $toInsert = [];
        $maxSort = $this->network->networkContent()->max('sort_order') ?? 0;

        foreach ($valid as $id) {
            if (in_array($id, $existing, true)) {
                continue;
            }

            $toInsert[] = [
                'network_id' => $this->network->id,
                'contentable_type' => Episode::class,
                'contentable_id' => $id,
                'sort_order' => ++$maxSort,
                'weight' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($toInsert)) {
            NetworkContent::insert($toInsert);

            Notification::make()
                ->success()
                ->title('Episodes Added')
                ->body('Added '.count($toInsert).' episodes to the network.')
                ->send();

            $this->dispatch('refreshRelationTable');
            $this->dispatch('networkContentAdded');
            $this->selected = [];
        } else {
            Notification::make()
                ->info()
                ->title('No new episodes to add')
                ->send();
        }
    }

    public function render()
    {
        return view('livewire.filament.networks.episode-picker', [
            'episodes' => $this->episodes,
            'seriesOptions' => $this->seriesOptions,
        ]);
    }
}
