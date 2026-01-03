<div>
    <!-- TMDB Search Modal -->
    <div
        x-data="{ show: @entangle('showModal') }"
        x-show="show"
        x-transition.opacity.duration.300ms
        x-on:open-tmdb-search.window="$wire.openSearch($event.detail)"
        class="fixed inset-0 z-50 overflow-y-auto"
        style="display: none;"
    >
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <!-- Backdrop -->
            <div
                class="fixed inset-0 bg-black/75 transition-opacity"
                @click="$wire.closeModal()"
            ></div>

            <!-- Modal Content -->
            <div
                class="inline-block w-full max-w-4xl my-8 overflow-hidden text-left align-middle transition-all transform bg-white dark:bg-gray-900 shadow-xl rounded-lg"
                x-show="show"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 transform translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 transform translate-y-0 sm:scale-100"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 transform translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 transform translate-y-4 sm:translate-y-0 sm:scale-95"
            >
                <!-- Header -->
                <div class="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                            Manual TMDB Search
                        </h3>
                        @if($originalTitle)
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Original: {{ $originalTitle }}
                            </p>
                        @endif
                    </div>
                    <button
                        @click="$wire.closeModal()"
                        class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none"
                    >
                        <x-heroicon-o-x-mark class="w-6 h-6" />
                    </button>
                </div>

                <!-- Search Form -->
                <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                    <form wire:submit="search" class="flex flex-col sm:flex-row gap-3">
                        <div class="flex-1">
                            <label for="search-query" class="sr-only">Search Title</label>
                            <input
                                type="text"
                                id="search-query"
                                wire:model="searchQuery"
                                placeholder="Enter title (e.g., Everybody Hates Chris)"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 focus:border-primary-500 focus:ring-primary-500"
                            >
                        </div>
                        <div class="w-full sm:w-28">
                            <label for="search-year" class="sr-only">Year</label>
                            <input
                                type="number"
                                id="search-year"
                                wire:model="searchYear"
                                placeholder="Year"
                                min="1900"
                                max="{{ date('Y') + 2 }}"
                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 focus:border-primary-500 focus:ring-primary-500"
                            >
                        </div>
                        <button
                            type="submit"
                            class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-primary-600 border border-transparent rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 disabled:opacity-50"
                            wire:loading.attr="disabled"
                        >
                            <x-heroicon-o-magnifying-glass class="w-4 h-4 mr-2" wire:loading.remove wire:target="search" />
                            <x-filament::loading-indicator class="h-4 w-4 mr-2" wire:loading wire:target="search" />
                            Search
                        </button>
                    </form>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Tip: Try searching in English for better results. The TMDB database has the most complete data in English.
                    </p>
                </div>

                <!-- Results -->
                <div class="max-h-[60vh] overflow-y-auto">
                    @if($isSearching)
                        <div class="flex items-center justify-center p-8">
                            <x-filament::loading-indicator class="h-8 w-8 text-primary-500" />
                            <span class="ml-3 text-gray-600 dark:text-gray-400">Searching TMDB...</span>
                        </div>
                    @elseif(count($results) > 0)
                        <div class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($results as $result)
                                <div
                                    class="flex p-4 hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer transition-colors"
                                    wire:click="selectResult({{ $result['tmdb_id'] }})"
                                    wire:loading.class="opacity-50 pointer-events-none"
                                >
                                    <!-- Poster -->
                                    <div class="flex-shrink-0 w-16 sm:w-20">
                                        @if($result['poster_path'])
                                            <img
                                                src="{{ $result['poster_path'] }}"
                                                alt="{{ $result['name'] ?? $result['title'] ?? '' }}"
                                                class="w-full rounded shadow-sm"
                                                loading="lazy"
                                            >
                                        @else
                                            <div class="w-full aspect-[2/3] bg-gray-200 dark:bg-gray-700 rounded flex items-center justify-center">
                                                <x-heroicon-o-film class="w-8 h-8 text-gray-400 dark:text-gray-500" />
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Info -->
                                    <div class="ml-4 flex-1 min-w-0">
                                        <div class="flex items-start justify-between">
                                            <div>
                                                <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                                    {{ $result['name'] ?? $result['title'] ?? 'Unknown' }}
                                                    @if($result['year'])
                                                        <span class="text-gray-500 dark:text-gray-400">({{ $result['year'] }})</span>
                                                    @endif
                                                </h4>
                                                @if(($result['original_name'] ?? $result['original_title'] ?? null) && ($result['original_name'] ?? $result['original_title']) !== ($result['name'] ?? $result['title']))
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                        Original: {{ $result['original_name'] ?? $result['original_title'] }}
                                                    </p>
                                                @endif
                                            </div>
                                            <div class="flex items-center space-x-2 ml-2 flex-shrink-0">
                                                @if($result['vote_average'])
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-500">
                                                        <x-heroicon-s-star class="w-3 h-3 mr-1" />
                                                        {{ number_format($result['vote_average'], 1) }}
                                                    </span>
                                                @endif
                                                @if(!empty($result['origin_country']))
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                                        {{ $result['origin_country'] }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>

                                        @if($result['overview'])
                                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-400 line-clamp-2">
                                                {{ $result['overview'] }}
                                            </p>
                                        @endif

                                        <div class="mt-2 flex items-center space-x-3 text-xs text-gray-500 dark:text-gray-400">
                                            <span class="inline-flex items-center">
                                                <span class="font-medium">TMDB:</span>
                                                <span class="ml-1">{{ $result['tmdb_id'] }}</span>
                                            </span>
                                            @if($result['first_air_date'] ?? $result['release_date'] ?? null)
                                                <span class="inline-flex items-center">
                                                    <x-heroicon-o-calendar class="w-3 h-3 mr-1" />
                                                    {{ $result['first_air_date'] ?? $result['release_date'] }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Select Arrow -->
                                    <div class="flex-shrink-0 ml-2 self-center">
                                        <x-heroicon-o-chevron-right class="w-5 h-5 text-gray-400" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @elseif($searchQuery && !$isSearching)
                        <div class="flex flex-col items-center justify-center p-8 text-center">
                            <x-heroicon-o-magnifying-glass class="w-12 h-12 text-gray-300 dark:text-gray-600" />
                            <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No results found</h4>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Try a different search term or remove the year filter.
                            </p>
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center p-8 text-center">
                            <x-heroicon-o-film class="w-12 h-12 text-gray-300 dark:text-gray-600" />
                            <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">Search TMDB</h4>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Enter a title to search for movies or TV series.
                            </p>
                        </div>
                    @endif
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-end gap-3 p-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                    <button
                        type="button"
                        @click="$wire.closeModal()"
                        class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
