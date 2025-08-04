@php
    $record = $getRecord();
    $class = class_basename($record);
    $route = $class === 'Epg' 
        ? route('api.epg.data', ['uuid' => $record?->uuid]) 
        : route('api.epg.playlist.data', ['uuid' => $record?->uuid]) ;
@endphp

<div 
    x-data="epgViewer({ 
        apiUrl: '{{ $route }}' 
    })"
    x-init="init(); loadEpgData()"
    x-on:beforeunload.window="destroy()"
    x-on:alpine:destroyed="destroy()"
    x-on:close-modal.window="destroy()"
    x-on:livewire:navigating.window="destroy()"
    class="w-full"
>
        <!-- Loading State -->
        <div x-show="loading" class="flex items-center justify-center p-8">
            <div class="flex items-center space-x-2">
                <svg class="animate-spin h-5 w-5 text-gray-500 dark:text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-sm text-gray-500 dark:text-gray-400">Loading EPG data...</span>
            </div>
        </div>

        <!-- Error State -->
        <div x-show="error && !loading" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="h-5 w-5 text-red-400 dark:text-red-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
                <p class="ml-2 text-sm text-red-700 dark:text-red-400" x-text="error"></p>
            </div>
        </div>

        <!-- EPG Content -->
        <div x-show="!loading && !error" class="space-y-4">
            <!-- Date Navigation -->
            <div class="flex items-center justify-between bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                <div class="flex items-center space-x-4">
                    <x-filament::button 
                        icon="heroicon-m-chevron-left"
                        icon-position="before"
                        color="gray"
                        @click="previousDay()"
                    >
                        Previous
                    </x-filament::button>
                    
                    <div class="flex flex-col">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100" x-text="epgData?.epg?.name || epgData?.playlist?.name || 'EPG Viewer'"></h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="formatDate(currentDate)"></p>
                    </div>
                    
                    <x-filament::button 
                        icon="heroicon-m-chevron-right"
                        icon-position="after"
                        color="gray"
                        @click="nextDay()"
                    >
                        Next
                    </x-filament::button>
                </div>

                <div class="flex items-center space-x-2">
                    <x-filament::button
                        icon="heroicon-m-calendar"
                        icon-position="before"
                        color="gray"
                        x-show="!isToday()"
                        @click="goToToday()"
                    >
                        Today
                    </x-filament::button>
                    <x-filament::button
                        icon="heroicon-m-clock"
                        icon-position="before"
                        color="gray"
                        x-show="isToday()"
                        @click="scrollToCurrentTime()"
                    >
                        Now
                    </x-filament::button>
                    {{-- <input 
                        type="date" 
                        x-model="currentDate"
                        @change="loadEpgData()"
                        class="px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                    > --}}
                </div>
            </div>

            <!-- EPG Grid Container -->
            <div class="bg-white dark:bg-gray-800 relative border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden" style="height: 600px; padding-bottom: 48px;">
                 <!-- Loading More Overlay -->
                <div 
                    x-show="loadingMore" 
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="absolute top-0 left-0 right-0 z-50 bg-indigo-50 dark:bg-indigo-900 border-b border-indigo-200 dark:border-indigo-800 px-4 py-2"
                >
                    <div class="flex items-center justify-center space-x-2">
                        <svg class="animate-spin h-4 w-4 text-indigo-500 dark:text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span class="text-sm text-indigo-700 dark:text-indigo-300">Loading more channels...</span>
                    </div>
                </div>
                <!-- Time Header -->
                <div class="sticky top-0 z-10 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600">
                    <div class="flex">
                        <!-- Channel Column Header -->
                        <div class="w-60 px-4 py-3 border-r border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-800">
                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Channels</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400 ml-2" x-text="`(${Object.keys(epgData?.channels || {}).length})`"></span>
                        </div>
                        <!-- Time Slots Header (Scrollable) -->
                        <div class="flex-1 relative overflow-hidden">
                            <div 
                                class="overflow-x-auto time-header-scroll" 
                                @scroll="document.querySelector('.timeline-scroll').scrollLeft = $el.scrollLeft"
                                style="scrollbar-width: none; -ms-overflow-style: none;"
                            >
                                <div class="flex relative" style="width: 2400px;"> <!-- 24 hours * 100px per hour -->
                                    <template x-for="hour in timeSlots" :key="hour">
                                        <div class="w-25 px-2 py-3 border-r border-gray-200 dark:border-gray-600 text-center bg-gray-100 dark:bg-gray-800" style="width: 100px;">
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300" x-text="formatTime(hour)"></span>
                                        </div>
                                    </template>
                                    <!-- Current time indicator (moves with content) -->
                                    <div 
                                        x-show="isToday() && currentTimePosition >= 0"
                                        class="absolute top-0 bottom-0 w-0.5 bg-red-500 z-10"
                                        :style="`left: ${currentTimePosition}px;`"
                                    >
                                        <div class="absolute -top-1 -left-1 w-2 h-2 bg-red-500 rounded-full"></div>
                                    </div>
                                </div>
                            </div>
                            <style>
                                .time-header-scroll::-webkit-scrollbar {
                                    display: none;
                                }
                            </style>
                        </div>
                    </div>
                </div>

                <!-- Scrollable Content Area -->
                <div class="flex h-full overflow-hidden" x-data="{ scrollContainer: null }" x-init="scrollContainer = $el.querySelector('.timeline-scroll')">
                    <!-- Channel List (Fixed vertically, scrolls with timeline) -->
                    <div class="w-60 border-r border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 overflow-hidden">
                        <div 
                            class="overflow-y-auto h-full"
                            @scroll="if (scrollContainer) scrollContainer.scrollTop = $el.scrollTop"
                            x-ref="channelScroll"
                        >
                            <template x-for="(channel, channelId) in epgData?.channels || {}" :key="channelId">
                                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-600 flex items-center space-x-3 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" style="height: 60px;">
                                    <div class="flex-shrink-0">
                                        <img 
                                            :src="channel.icon || '/placeholder.png'" 
                                            :alt="channel.display_name"
                                            class="w-8 h-8 rounded object-contain"
                                            onerror="this.src='/placeholder.png'"
                                        >
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate" x-text="channel.display_name" x-tooltip="channel.display_name"></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="channelId"></p>
                                    </div>
                                    <!-- Play Buttons (only show if channel has URL) -->
                                    <div x-show="channel.url" class="flex-shrink-0 flex space-x-1">
                                        {{-- 
                                        // Disabled - using floating player only for now
                                        // If you want to re-enable the modal player, uncomment this block
                                        // and the `@livewire('stream-player')` in the footer of this component
                                        <button 
                                            @click.stop="
                                                console.log('Play button clicked for channel:', channel); 
                                                window.dispatchEvent(new CustomEvent('openStreamPlayer', { detail: channel }))
                                            "
                                            class="p-2 text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-200 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 rounded-full transition-colors"
                                            title="Play Stream in Modal"
                                        >
                                            <x-heroicon-s-play class="w-4 h-4" />
                                        </button> --}}
                                        
                                        <button 
                                            @click.stop="
                                                window.dispatchEvent(new CustomEvent('openFloatingStream', { detail: channel }))
                                            "
                                            class="p-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-900/20 rounded-full transition-colors"
                                            title="Play Stream in Floating Window"
                                        >
                                            <x-heroicon-s-play class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </template>

                            <!-- Loading indicator at bottom when more data is being loaded -->
                            <div x-show="hasMore && !loadingMore" class="px-4 py-3 text-center">
                                <div class="text-xs text-gray-500 dark:text-gray-400">Scroll down for more channels...</div>
                            </div>
                        </div>
                    </div>

                    <!-- Programme Timeline (Scrollable) -->
                    <div 
                        class="flex-1 overflow-auto relative timeline-scroll"
                        @scroll="
                            $refs.channelScroll.scrollTop = $el.scrollTop;
                            document.querySelector('.time-header-scroll').scrollLeft = $el.scrollLeft;
                            handleScroll($event);
                        "
                    >
                        <div class="relative overflow-hidden" style="width: 2400px;"> <!-- 24 hours * 100px per hour -->
                            <!-- Current time indicator for programme area -->
                            <div 
                                x-show="isToday() && currentTimePosition >= 0"
                                class="absolute top-0 bottom-0 w-0.5 bg-red-500 z-30 pointer-events-none"
                                :style="`left: ${currentTimePosition}px;`"
                            ></div>
                            
                            <template x-for="(channel, channelId) in epgData?.channels || {}" :key="channelId">
                                <div class="relative border-b border-gray-100 dark:border-gray-600" style="height: 60px;">
                                    <!-- Time grid background -->
                                    <div class="absolute inset-0 flex">
                                        <template x-for="hour in timeSlots" :key="`${channelId}-${hour}`">
                                            <div class="w-25 border-r border-gray-200 dark:border-gray-600" style="width: 100px;"></div>
                                        </template>
                                    </div>
                                    
                                    <!-- Programme blocks -->
                                    <div class="absolute inset-0">
                                        <template x-for="programme in getChannelProgrammes(channelId)" :key="`${channelId}-${programme.start}-${programme.title}`">
                                            <div 
                                                class="absolute top-1 bottom-1 rounded shadow-sm cursor-pointer group transition-all duration-200"
                                                :class="getProgrammeColorClass(programme)"
                                                :style="getProgrammeStyle(programme)"
                                                @click="selectProgramme(programme)"
                                                x-data="{ 
                                                    get tooltipContent() {
                                                        let content = '<p><strong>' + programme.title + '</strong></p>';
                                                        if (programme.new) {
                                                            content += '<small>New Episode</small><br/>';
                                                        }
                                                        if (programme.episode_num) {
                                                            // Assuming episode_num is in xmltv_ns format
                                                            let season = 0, episode = 0;
                                                            const parts = programme.episode_num.split('.');
                                                            if (parts.length > 0) {
                                                                season = parseInt(parts[0], 10) + 1; // xmltv_ns is zero-based
                                                            }
                                                            if (parts.length > 1) {
                                                                episode = parseInt(parts[1], 10) + 1;
                                                            }
                                                            if (season > 0 && episode > 0) {
                                                                content += '<small>Season ' + season + ', Episode ' + episode + '</small><br/>';
                                                            } else if (season > 0) {
                                                                content += '<small>Season ' + season + '</small><br/>';
                                                            } else if (episode > 0) {
                                                                content += '<small>Episode ' + episode + '</small><br/>';
                                                            }
                                                        }
                                                        if (programme.desc && programme.desc.trim()) {
                                                            content += '<p>' + programme.desc + '</p>';
                                                        }
                                                        if (programme.category && programme.category.trim()) {
                                                            content += '<small>Category: ' + programme.category + '</small>';
                                                        }
                                                        return content;
                                                    }
                                                }"
                                                x-tooltip.html="tooltipContent"
                                            >
                                                <div class="p-2 h-full overflow-hidden flex flex-col justify-center">
                                                    <div class="text-xs font-medium text-gray-900 dark:text-gray-100 truncate leading-tight" x-text="programme.title"></div>
                                                    <div class="text-xs text-gray-600 dark:text-gray-300 truncate" x-text="formatProgrammeTime(programme)"></div>
                                                    <div x-show="programme.new" class="absolute top-0.5 right-0.5 bg-gray-500 text-white text-xs px-1 rounded-xl opacity-100" style="font-size: 10px; line-height: 1;">
                                                        New
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Programme Details Modal -->
            <div x-show="selectedProgramme" x-transition.opacity class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
                <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                    <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity" @click="selectedProgramme = null"></div>
                    
                    <div class="inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white dark:bg-gray-800 shadow-xl rounded-lg">
                        <div class="flex items-start justify-between">
                            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100" x-text="selectedProgramme?.title"></h3>
                            <button @click="selectedProgramme = null" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300">
                                <x-heroicon-s-x-mark class="w-6 h-6" />
                            </button>
                        </div>
                        
                        <div class="mt-4 space-y-3">
                            <div>
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Time:</span>
                                <span class="text-sm text-gray-900 dark:text-gray-100 ml-2" x-text="selectedProgramme ? formatProgrammeTime(selectedProgramme) : ''"></span>
                            </div>
                            
                            <div x-show="selectedProgramme?.category">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Category:</span>
                                <span class="text-sm text-gray-900 dark:text-gray-100 ml-2" x-text="selectedProgramme?.category"></span>
                            </div>
                            
                            <div x-show="selectedProgramme?.desc" class="space-y-2">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Description:</span>
                                <p class="text-sm text-gray-600 dark:text-gray-400" x-text="selectedProgramme?.desc"></p>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div x-show="selectedProgramme?.channel?.url" class="flex space-x-3 pt-4 border-t border-gray-200 dark:border-gray-600">
                                <x-filament::button
                                    color="primary"
                                    size="sm"
                                    @click="
                                        console.log('Opening modal stream for channel:', selectedProgramme?.channel); 
                                        window.dispatchEvent(new CustomEvent('openStreamPlayer', { detail: selectedProgramme?.channel }));
                                        selectedProgramme = null;
                                    "
                                >
                                    <x-heroicon-m-play class="w-4 h-4 mr-2" />
                                    Play in Modal
                                </x-filament::button>
                                
                                <x-filament::button
                                    color="gray"
                                    size="sm"
                                    @click="
                                        console.log('Opening floating stream for channel:', selectedProgramme?.channel); 
                                        window.dispatchEvent(new CustomEvent('openFloatingStream', { detail: selectedProgramme?.channel }));
                                        selectedProgramme = null;
                                    "
                                >
                                    <x-heroicon-m-window class="w-4 h-4 mr-2" />
                                    Play Floating
                                </x-filament::button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stream Player Component -->
        {{-- @livewire('stream-player') --}}

        <!-- Floating Stream Players -->
        <x-floating-stream-players />
</div>