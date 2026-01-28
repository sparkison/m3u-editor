<x-filament-panels::page
    @class([
        'fi-resource-view-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    {{-- Hero Backdrop Section --}}
    @php
        $record = $this->record;
        $backdrops = $record->backdrop_path;
        if (is_string($backdrops)) {
            $backdrops = json_decode($backdrops, true) ?? [];
        }
        $backdropUrl = null;
        if (!empty($backdrops) && is_array($backdrops)) {
            $backdropUrl = is_array($backdrops[0] ?? null) ? ($backdrops[0]['url'] ?? null) : ($backdrops[0] ?? null);
        }
    @endphp

    @if($backdropUrl)
        <div class="relative -mx-4 -mt-4 mb-6 overflow-hidden rounded-xl" style="min-height: 400px;">
            {{-- Backdrop Image --}}
            <div class="absolute inset-0">
                <img src="{{ $backdropUrl }}" alt="{{ $record->name }}" class="w-full h-full object-cover" />
                <div class="absolute inset-0 bg-gradient-to-r from-gray-900 via-gray-900/80 to-transparent"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-transparent to-transparent"></div>
            </div>

            {{-- Content Overlay --}}
            <div class="relative z-10 p-8 flex flex-col md:flex-row gap-8">
                {{-- Poster --}}
                <div class="flex-shrink-0">
                    @php
                        $coverUrl = \App\Facades\LogoFacade::getSeriesLogoUrl($record);
                    @endphp
                    @if($coverUrl)
                        <img src="{{ $coverUrl }}" alt="{{ $record->name }}" class="w-48 h-72 object-cover rounded-lg shadow-2xl ring-1 ring-white/20" />
                    @else
                        <div class="w-48 h-72 bg-gray-800 rounded-lg shadow-2xl flex items-center justify-center">
                            <x-heroicon-o-film class="w-16 h-16 text-gray-600" />
                        </div>
                    @endif
                </div>

                {{-- Info --}}
                <div class="flex-1 text-white space-y-4">
                    <h1 class="text-4xl font-bold">{{ $record->name }}</h1>

                    {{-- Metadata Badges --}}
                    <div class="flex flex-wrap gap-2 items-center text-sm">
                        @if($record->release_date)
                            <span class="px-3 py-1 bg-white/10 rounded-full">{{ $record->release_date }}</span>
                        @endif
                        @if($record->genre)
                            <span class="px-3 py-1 bg-white/10 rounded-full">{{ $record->genre }}</span>
                        @endif
                        @if($record->rating)
                            <span class="px-3 py-1 bg-yellow-500/20 text-yellow-300 rounded-full flex items-center gap-1">
                                <x-heroicon-s-star class="w-4 h-4" />
                                {{ $record->rating }}
                            </span>
                        @endif
                        @php
                            $seasonsCount = $record->seasons()->count();
                            $episodesCount = $record->episodes()->count();
                        @endphp
                        @if($seasonsCount > 0)
                            <span class="px-3 py-1 bg-white/10 rounded-full">{{ $seasonsCount }} {{ Str::plural('Season', $seasonsCount) }}</span>
                        @endif
                        @if($episodesCount > 0)
                            <span class="px-3 py-1 bg-white/10 rounded-full">{{ $episodesCount }} {{ Str::plural('Episode', $episodesCount) }}</span>
                        @endif

                        {{-- Status Badge --}}
                        <span class="px-3 py-1 rounded-full {{ $record->enabled ? 'bg-green-500/20 text-green-300' : 'bg-red-500/20 text-red-300' }}">
                            {{ $record->enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>

                    {{-- Plot --}}
                    @if($record->plot)
                        <p class="text-gray-300 max-w-2xl leading-relaxed">{{ Str::limit($record->plot, 500) }}</p>
                    @endif

                    {{-- External IDs --}}
                    @if($record->tmdb_id || $record->tvdb_id || $record->imdb_id)
                        <div class="flex gap-3 pt-2">
                            @if($record->tmdb_id)
                                <a href="https://www.themoviedb.org/tv/{{ $record->tmdb_id }}" target="_blank" class="px-3 py-1 bg-blue-600/30 hover:bg-blue-600/50 text-blue-300 rounded text-xs transition-colors">
                                    TMDB: {{ $record->tmdb_id }}
                                </a>
                            @endif
                            @if($record->tvdb_id)
                                <a href="https://thetvdb.com/?tab=series&id={{ $record->tvdb_id }}" target="_blank" class="px-3 py-1 bg-green-600/30 hover:bg-green-600/50 text-green-300 rounded text-xs transition-colors">
                                    TVDB: {{ $record->tvdb_id }}
                                </a>
                            @endif
                            @if($record->imdb_id)
                                <a href="https://www.imdb.com/title/{{ $record->imdb_id }}" target="_blank" class="px-3 py-1 bg-yellow-600/30 hover:bg-yellow-600/50 text-yellow-300 rounded text-xs transition-colors">
                                    {{ $record->imdb_id }}
                                </a>
                            @endif
                        </div>
                    @endif

                    {{-- YouTube Trailer --}}
                    @if($record->youtube_trailer)
                        <div class="pt-2">
                            <a href="https://www.youtube.com/watch?v={{ $record->youtube_trailer }}" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                                <x-heroicon-s-play class="w-5 h-5" />
                                Watch Trailer
                            </a>
                        </div>
                    @endif

                    {{-- Cast & Director --}}
                    @if($record->director || $record->cast)
                        <div class="pt-4 border-t border-white/10 space-y-2">
                            @if($record->director)
                                <p class="text-sm"><span class="text-gray-400">Director:</span> <span class="text-white">{{ $record->director }}</span></p>
                            @endif
                            @if($record->cast)
                                <p class="text-sm"><span class="text-gray-400">Cast:</span> <span class="text-white">{{ Str::limit($record->cast, 200) }}</span></p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @else
        {{-- Fallback without backdrop --}}
        <div class="mb-6 p-6 bg-gray-100 dark:bg-gray-800 rounded-xl">
            <div class="flex flex-col md:flex-row gap-6">
                {{-- Poster --}}
                <div class="flex-shrink-0">
                    @php
                        $coverUrl = \App\Facades\LogoFacade::getSeriesLogoUrl($record);
                    @endphp
                    @if($coverUrl)
                        <img src="{{ $coverUrl }}" alt="{{ $record->name }}" class="w-40 h-60 object-cover rounded-lg shadow-lg" />
                    @else
                        <div class="w-40 h-60 bg-gray-300 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                            <x-heroicon-o-film class="w-12 h-12 text-gray-400" />
                        </div>
                    @endif
                </div>

                {{-- Info --}}
                <div class="flex-1 space-y-3">
                    <div class="flex flex-wrap gap-2 items-center text-sm">
                        @if($record->release_date)
                            <span class="px-2 py-1 bg-gray-200 dark:bg-gray-700 rounded">{{ $record->release_date }}</span>
                        @endif
                        @if($record->genre)
                            <span class="px-2 py-1 bg-gray-200 dark:bg-gray-700 rounded">{{ $record->genre }}</span>
                        @endif
                        @if($record->rating)
                            <span class="px-2 py-1 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300 rounded flex items-center gap-1">
                                <x-heroicon-s-star class="w-3 h-3" />
                                {{ $record->rating }}
                            </span>
                        @endif
                        <span class="px-2 py-1 rounded {{ $record->enabled ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300' : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' }}">
                            {{ $record->enabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>

                    @if($record->plot)
                        <p class="text-gray-600 dark:text-gray-300">{{ Str::limit($record->plot, 300) }}</p>
                    @endif

                    @if($record->director || $record->cast)
                        <div class="text-sm space-y-1">
                            @if($record->director)
                                <p><span class="text-gray-500">Director:</span> {{ $record->director }}</p>
                            @endif
                            @if($record->cast)
                                <p><span class="text-gray-500">Cast:</span> {{ Str::limit($record->cast, 150) }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Seasons Grid --}}
    @php
        $seasons = $record->seasons()->orderBy('season_number')->get();
    @endphp
    @if($seasons->isNotEmpty())
        <div class="mb-6">
            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
                <x-heroicon-o-rectangle-stack class="w-5 h-5" />
                Seasons
            </h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-4">
                @foreach($seasons as $season)
                    @php
                        $cover = $season->cover_big ?? $season->cover;
                        $totalEpisodes = $record->episodes()->where('season', $season->season_number)->count();
                        $enabledEpisodes = $record->episodes()->where('season', $season->season_number)->where('enabled', true)->count();
                    @endphp
                    <div class="group bg-white dark:bg-gray-800 rounded-lg shadow-sm ring-1 ring-gray-200 dark:ring-gray-700 overflow-hidden hover:ring-primary-500 dark:hover:ring-primary-500 transition-all">
                        @if($cover)
                            <div class="aspect-[2/3] overflow-hidden">
                                <img src="{{ $cover }}" alt="{{ $season->name }}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
                            </div>
                        @else
                            <div class="aspect-[2/3] bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                                <x-heroicon-o-tv class="w-8 h-8 text-gray-400" />
                            </div>
                        @endif
                        <div class="p-3">
                            <div class="font-medium text-sm truncate">{{ $season->name }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $enabledEpisodes }}/{{ $totalEpisodes }} episodes
                            </div>
                            @if($totalEpisodes > 0)
                                <div class="mt-2 h-1 bg-gray-200 dark:bg-gray-600 rounded-full overflow-hidden">
                                    <div class="h-full bg-primary-500 rounded-full" style="width: {{ ($enabledEpisodes / $totalEpisodes) * 100 }}%"></div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-filament-panels::page>
