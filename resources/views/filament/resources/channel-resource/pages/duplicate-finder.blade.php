<x-filament-panels::page>
    <div class="space-y-6">
        @if (count($this->duplicateGroups) > 0)
            <div class="bg-blue-50 dark:bg-blue-950 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                <div class="flex items-center space-x-2">
                    <x-heroicon-o-information-circle class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                    <div>
                        <h3 class="text-sm font-medium text-blue-800 dark:text-blue-200">
                            Found {{ count($this->duplicateGroups) }} duplicate groups with {{ collect($this->duplicateGroups)->flatten(1)->count() }} total channels
                        </h3>
                        <p class="text-sm text-blue-600 dark:text-blue-300 mt-1">
                            Review the groups below and set primary channels, then use "Auto-Create Failovers" to establish fallback relationships.
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @foreach ($this->duplicateGroups as $groupId => $channels)
                    <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                        <div class="bg-gray-50 dark:bg-gray-800 px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                            <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                Duplicate Group #{{ $groupId }}
                                <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
                                    ({{ $channels->count() }} channels)
                                </span>
                            </h4>
                        </div>
                        
                        <div class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($channels as $channel)
                                <div class="p-4 flex items-center justify-between">
                                    <div class="flex items-center space-x-4">
                                        @if ($channel->logo)
                                            <img src="{{ $channel->logo }}" alt="{{ $channel->title }}" class="w-8 h-8 rounded object-cover">
                                        @else
                                            <div class="w-8 h-8 bg-gray-200 dark:bg-gray-700 rounded flex items-center justify-center">
                                                <x-heroicon-o-tv class="w-4 h-4 text-gray-400" />
                                            </div>
                                        @endif
                                        
                                        <div>
                                            <div class="font-medium text-gray-900 dark:text-gray-100">
                                                {{ $channel->title_custom ?: $channel->title }}
                                            </div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                {{ $channel->playlist->name ?? 'Unknown Playlist' }}
                                                @if (isset($channel->similarity_score))
                                                    • {{ number_format($channel->similarity_score * 100, 1) }}% match
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center space-x-2">
                                        @if ($channel->is_fallback_candidate)
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                                Fallback Candidate
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                Primary
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-12">
                <x-heroicon-o-document-duplicate class="mx-auto h-12 w-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No duplicate channels found</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    No channels with similarity above {{ number_format($this->similarityThreshold * 100, 0) }}% were found.
                </p>
                <div class="mt-6">
                    <button 
                        type="button" 
                        wire:click="$set('similarityThreshold', 0.6)"
                        class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                        Try Lower Threshold (60%)
                    </button>
                </div>
            </div>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
