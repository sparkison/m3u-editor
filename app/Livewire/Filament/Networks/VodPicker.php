<?php

namespace App\Livewire\Filament\Networks;

use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkContent;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class VodPicker extends Component
{
    use WithPagination;

    public Network $network;

    public string $search = '';

    public string $genreFilter = '';

    public string $sortBy = 'name';

    public string $sortDirection = 'asc';

    public int $perPage = 25;

    public array $selected = [];

    protected $queryString = [
        'search' => ['except' => ''],
        'genreFilter' => ['except' => ''],
        'sortBy' => ['except' => 'name'],
        'sortDirection' => ['except' => 'asc'],
    ];

    protected $listeners = [
        'networkContentAdded' => '$refresh',
    ];

    public function mount(Network $network): void
    {
        $this->network = $network->load('mediaServerIntegration');
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingGenreFilter(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
        $this->resetPage();
    }

    protected function getPlaylistId(): ?int
    {
        return $this->network->mediaServerIntegration?->playlist_id;
    }

    public function getGenreOptionsProperty(): array
    {
        $playlistId = $this->getPlaylistId();

        if (! $playlistId) {
            return [];
        }

        // Extract unique genres from VOD info
        $genres = Channel::where('playlist_id', $playlistId)
            ->where('is_vod', true)
            ->whereNotNull('info')
            ->get()
            ->pluck('info.genre')
            ->filter()
            ->flatMap(fn ($g) => array_map('trim', explode(',', $g)))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $genres;
    }

    protected function getExistingVodIds(): array
    {
        return $this->network->networkContent()
            ->where('contentable_type', Channel::class)
            ->pluck('contentable_id')
            ->map(fn ($i) => (int) $i)
            ->all();
    }

    protected function query()
    {
        $playlistId = $this->getPlaylistId();

        if (! $playlistId) {
            return Channel::query()->whereNull('id');
        }

        $query = Channel::query()
            ->where('playlist_id', $playlistId)
            ->where('is_vod', true);

        $existing = $this->getExistingVodIds();
        if (! empty($existing)) {
            $query->whereNotIn('id', $existing);
        }

        if ($this->search !== '') {
            $q = Str::lower($this->search);
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$q}%"]);
        }

        // Genre filter
        if ($this->genreFilter !== '') {
            $genre = $this->genreFilter;
            $query->where(function ($sub) use ($genre) {
                $sub->whereRaw("info->>'genre' ILIKE ?", ["%{$genre}%"]);
            });
        }

        // Sorting
        $sortColumn = match ($this->sortBy) {
            'rating' => "COALESCE((info->>'rating')::float, 0)",
            'runtime' => "COALESCE((info->>'duration_secs')::int, 0)",
            'genre' => "COALESCE(info->>'genre', '')",
            'mpaa' => "COALESCE(info->>'mpaa_rating', '')",
            default => 'name',
        };

        if (in_array($this->sortBy, ['rating', 'runtime', 'genre', 'mpaa'])) {
            $query->orderByRaw("{$sortColumn} {$this->sortDirection}");
        } else {
            $query->orderBy($this->sortBy, $this->sortDirection);
        }

        return $query;
    }

    public function getVodsProperty()
    {
        return $this->query()->paginate($this->perPage);
    }

    public function toggleSelectAllOnPage(): void
    {
        $ids = $this->vods->pluck('id')->map(fn ($i) => (int) $i)->all();

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
                ->title('No movies selected')
                ->send();

            return;
        }

        $playlistId = $this->getPlaylistId();

        // Server-side validation
        $valid = Channel::whereIn('id', $selected)
            ->where('playlist_id', $playlistId)
            ->where('is_vod', true)
            ->pluck('id')
            ->map(fn ($i) => (int) $i)
            ->all();

        $existing = $this->getExistingVodIds();

        $toInsert = [];
        $maxSort = $this->network->networkContent()->max('sort_order') ?? 0;

        foreach ($valid as $id) {
            if (in_array($id, $existing, true)) {
                continue;
            }

            $toInsert[] = [
                'network_id' => $this->network->id,
                'contentable_type' => Channel::class,
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
                ->title('Movies Added')
                ->body('Added '.count($toInsert).' movies to the network.')
                ->send();

            $this->dispatch('refreshRelationTable');
            $this->dispatch('networkContentAdded');
            $this->selected = [];
        } else {
            Notification::make()
                ->info()
                ->title('No new movies to add')
                ->send();
        }
    }

    public function render()
    {
        return view('livewire.filament.networks.vod-picker', [
            'vods' => $this->vods,
        ]);
    }
}
