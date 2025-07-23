<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @php
        $record = $getRecord();
        $epgUuid = $record->uuid;
    @endphp

    <div 
        x-data="epgViewer({ 
            epgUuid: '{{ $epgUuid }}',
            apiUrl: '{{ route('api.epg.data', ['uuid' => $epgUuid]) }}' 
        })"
        x-init="init(); loadEpgData()"
        class="w-full"
    >
        <!-- Loading State -->
        <div x-show="loading" class="flex items-center justify-center p-8">
            <div class="flex items-center space-x-2">
                <svg class="animate-spin h-5 w-5 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-sm text-gray-500">Loading EPG data...</span>
            </div>
        </div>

        <!-- Error State -->
        <div x-show="error && !loading" class="bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
                <p class="ml-2 text-sm text-red-700" x-text="error"></p>
            </div>
        </div>

        <!-- EPG Content -->
        <div x-show="!loading && !error" class="space-y-4">
            <!-- Date Navigation -->
            <div class="flex items-center justify-between bg-white border border-gray-200 rounded-lg p-4">
                <div class="flex items-center space-x-4">
                    <button 
                        @click="previousDay()"
                        class="flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-gray-100 border border-gray-300 rounded-md hover:bg-gray-200 transition-colors"
                    >
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        Previous
                    </button>
                    
                    <div class="flex flex-col">
                        <h3 class="text-lg font-medium text-gray-900" x-text="epgData?.epg?.name || 'EPG Viewer'"></h3>
                        <p class="text-sm text-gray-500" x-text="formatDate(currentDate)"></p>
                    </div>
                    
                    <button 
                        @click="nextDay()"
                        class="flex items-center px-3 py-2 text-sm font-medium text-gray-700 bg-gray-100 border border-gray-300 rounded-md hover:bg-gray-200 transition-colors"
                    >
                        Next
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>

                <div class="flex items-center space-x-2">
                    <button 
                        @click="goToToday()"
                        class="px-3 py-2 text-sm font-medium text-gray-700 bg-gray-100 border border-gray-300 rounded-md hover:bg-gray-200 transition-colors"
                    >
                        Today
                    </button>
                    <input 
                        type="date" 
                        x-model="currentDate"
                        @change="loadEpgData()"
                        class="px-3 py-2 text-sm border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    >
                </div>
            </div>

            <!-- EPG Grid Container -->
            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden" style="height: 600px;">
                <!-- Time Header -->
                <div class="sticky top-0 z-20 bg-gray-50 border-b border-gray-200">
                    <div class="flex">
                        <!-- Channel Column Header -->
                        <div class="w-48 px-4 py-3 border-r border-gray-200 bg-gray-100">
                            <span class="text-sm font-medium text-gray-900">Channels</span>
                            <span class="text-xs text-gray-500 ml-2" x-text="`(${Object.keys(epgData?.channels || {}).length})`"></span>
                        </div>
                        <!-- Time Slots Header -->
                        <div class="flex-1 relative">
                            <div class="flex" style="width: 2400px;"> <!-- 24 hours * 100px per hour -->
                                <template x-for="hour in timeSlots" :key="hour">
                                    <div class="w-25 px-2 py-3 border-r border-gray-200 text-center bg-gray-100" style="width: 100px;">
                                        <span class="text-xs font-medium text-gray-700" x-text="formatTime(hour)"></span>
                                    </div>
                                </template>
                            </div>
                            <!-- Current time indicator -->
                            <div 
                                x-show="isToday() && currentTimePosition >= 0"
                                class="absolute top-0 bottom-0 w-0.5 bg-red-500 z-10"
                                :style="`left: ${currentTimePosition}px;`"
                            >
                                <div class="absolute -top-1 -left-1 w-2 h-2 bg-red-500 rounded-full"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Scrollable Content Area -->
                <div class="flex h-full overflow-hidden">
                    <!-- Channel List (Fixed) -->
                    <div class="w-48 overflow-y-auto border-r border-gray-200 bg-gray-50">
                        <template x-for="(channel, channelId) in epgData?.channels || {}" :key="channelId">
                            <div class="px-4 py-3 border-b border-gray-100 flex items-center space-x-3 hover:bg-gray-100 transition-colors" style="height: 60px;">
                                <div class="flex-shrink-0">
                                    <img 
                                        :src="channel.icon || '/placeholder.png'" 
                                        :alt="channel.display_name"
                                        class="w-8 h-8 rounded object-cover"
                                        onerror="this.src='/placeholder.png'"
                                    >
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 truncate" x-text="channel.display_name"></p>
                                    <p class="text-xs text-gray-500 truncate" x-text="channelId"></p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Programme Timeline (Scrollable) -->
                    <div class="flex-1 overflow-auto relative">
                        <div style="width: 2400px;"> <!-- 24 hours * 100px per hour -->
                            <template x-for="(channel, channelId) in epgData?.channels || {}" :key="channelId">
                                <div class="relative border-b border-gray-100" style="height: 60px;">
                                    <!-- Time grid background -->
                                    <div class="absolute inset-0 flex">
                                        <template x-for="hour in timeSlots" :key="`${channelId}-${hour}`">
                                            <div class="w-25 border-r border-gray-200" style="width: 100px;"></div>
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
                                                x-data="{ showTooltip: false }"
                                                @mouseenter="showTooltip = true"
                                                @mouseleave="showTooltip = false"
                                            >
                                                <div class="p-2 h-full overflow-hidden flex flex-col justify-center">
                                                    <div class="text-xs font-medium text-gray-900 truncate leading-tight" x-text="programme.title"></div>
                                                    <div class="text-xs text-gray-600 truncate" x-text="formatProgrammeTime(programme)"></div>
                                                </div>
                                                
                                                <!-- Enhanced Tooltip -->
                                                <div 
                                                    x-show="showTooltip"
                                                    x-transition:enter="transition ease-out duration-200"
                                                    x-transition:enter-start="opacity-0 transform scale-95"
                                                    x-transition:enter-end="opacity-100 transform scale-100"
                                                    x-transition:leave="transition ease-in duration-150"
                                                    x-transition:leave-start="opacity-100 transform scale-100"
                                                    x-transition:leave-end="opacity-0 transform scale-95"
                                                    class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 px-3 py-2 bg-gray-900 text-white text-xs rounded-lg z-30 pointer-events-none max-w-xs shadow-lg"
                                                    style="min-width: 200px;"
                                                >
                                                    <div class="font-medium" x-text="programme.title"></div>
                                                    <div class="text-gray-300 mt-1" x-text="formatProgrammeTime(programme)"></div>
                                                    <div x-show="programme.desc" class="mt-1 text-gray-200" x-text="programme.desc"></div>
                                                    <div x-show="programme.category" class="mt-1 text-blue-300 text-xs" x-text="'Category: ' + programme.category"></div>
                                                    <!-- Arrow -->
                                                    <div class="absolute top-full left-1/2 transform -translate-x-1/2 w-0 h-0 border-l-4 border-r-4 border-t-4 border-transparent border-t-gray-900"></div>
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
                    
                    <div class="inline-block w-full max-w-lg p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-lg">
                        <div class="flex items-start justify-between">
                            <h3 class="text-lg font-medium text-gray-900" x-text="selectedProgramme?.title"></h3>
                            <button @click="selectedProgramme = null" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                        
                        <div class="mt-4 space-y-3">
                            <div>
                                <span class="text-sm font-medium text-gray-700">Time:</span>
                                <span class="text-sm text-gray-900 ml-2" x-text="selectedProgramme ? formatProgrammeTime(selectedProgramme) : ''"></span>
                            </div>
                            
                            <div x-show="selectedProgramme?.category">
                                <span class="text-sm font-medium text-gray-700">Category:</span>
                                <span class="text-sm text-gray-900 ml-2" x-text="selectedProgramme?.category"></span>
                            </div>
                            
                            <div x-show="selectedProgramme?.desc" class="space-y-2">
                                <span class="text-sm font-medium text-gray-700">Description:</span>
                                <p class="text-sm text-gray-600" x-text="selectedProgramme?.desc"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function epgViewer(config) {
            return {
                epgUuid: config.epgUuid,
                apiUrl: config.apiUrl,
                loading: false,
                error: null,
                epgData: null,
                currentDate: new Date().toISOString().split('T')[0],
                timeSlots: [],
                selectedProgramme: null,
                currentTimePosition: -1,

                init() {
                    this.generateTimeSlots();
                    this.updateCurrentTime();
                    // Update current time every minute
                    setInterval(() => this.updateCurrentTime(), 60000);
                },

                generateTimeSlots() {
                    this.timeSlots = [];
                    for (let hour = 0; hour < 24; hour++) {
                        this.timeSlots.push(hour);
                    }
                },

                updateCurrentTime() {
                    if (this.isToday()) {
                        const now = new Date();
                        const hours = now.getHours() + now.getMinutes() / 60;
                        this.currentTimePosition = hours * 100; // 100px per hour
                    } else {
                        this.currentTimePosition = -1;
                    }
                },

                isToday() {
                    const today = new Date().toISOString().split('T')[0];
                    return this.currentDate === today;
                },

                async loadEpgData() {
                    this.loading = true;
                    this.error = null;
                    this.selectedProgramme = null;

                    try {
                        const response = await fetch(this.apiUrl + `?start_date=${this.currentDate}&end_date=${this.currentDate}`);
                        
                        if (!response.ok) {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }

                        this.epgData = await response.json();
                        this.updateCurrentTime();
                    } catch (error) {
                        console.error('Error loading EPG data:', error);
                        this.error = 'Failed to load EPG data. Please try again.';
                    } finally {
                        this.loading = false;
                    }
                },

                selectProgramme(programme) {
                    this.selectedProgramme = programme;
                },

                previousDay() {
                    const date = new Date(this.currentDate);
                    date.setDate(date.getDate() - 1);
                    this.currentDate = date.toISOString().split('T')[0];
                    this.loadEpgData();
                },

                nextDay() {
                    const date = new Date(this.currentDate);
                    date.setDate(date.getDate() + 1);
                    this.currentDate = date.toISOString().split('T')[0];
                    this.loadEpgData();
                },

                goToToday() {
                    this.currentDate = new Date().toISOString().split('T')[0];
                    this.loadEpgData();
                },

                formatDate(dateStr) {
                    const date = new Date(dateStr);
                    return date.toLocaleDateString('en-US', { 
                        weekday: 'long', 
                        year: 'numeric', 
                        month: 'long', 
                        day: 'numeric' 
                    });
                },

                formatTime(hour) {
                    return new Date(2000, 0, 1, hour).toLocaleTimeString('en-US', { 
                        hour: '2-digit', 
                        minute: '2-digit',
                        hour12: false 
                    });
                },

                formatProgrammeTime(programme) {
                    const start = new Date(programme.start);
                    const stop = programme.stop ? new Date(programme.stop) : null;
                    
                    const startTime = start.toLocaleTimeString('en-US', { 
                        hour: '2-digit', 
                        minute: '2-digit',
                        hour12: false 
                    });
                    
                    if (stop) {
                        const stopTime = stop.toLocaleTimeString('en-US', { 
                            hour: '2-digit', 
                            minute: '2-digit',
                            hour12: false 
                        });
                        return `${startTime} - ${stopTime}`;
                    }
                    
                    return startTime;
                },

                getChannelProgrammes(channelId) {
                    return this.epgData?.programmes?.[channelId] || [];
                },

                getProgrammeStyle(programme) {
                    const start = new Date(programme.start);
                    const stop = programme.stop ? new Date(programme.stop) : new Date(start.getTime() + 30 * 60 * 1000);
                    
                    const dayStart = new Date(start);
                    dayStart.setHours(0, 0, 0, 0);
                    
                    const startHours = (start - dayStart) / (1000 * 60 * 60);
                    const durationHours = (stop - start) / (1000 * 60 * 60);
                    
                    const leftPos = startHours * 100; // 100px per hour
                    const width = Math.max(durationHours * 100, 60); // Minimum 60px width
                    
                    return `left: ${leftPos}px; width: ${width}px;`;
                },

                getProgrammeColorClass(programme) {
                    const now = new Date();
                    const start = new Date(programme.start);
                    const stop = programme.stop ? new Date(programme.stop) : new Date(start.getTime() + 30 * 60 * 1000);
                    
                    if (this.isToday() && start <= now && stop >= now) {
                        return 'bg-green-200 border border-green-400 hover:bg-green-300'; // Currently playing
                    } else if (start > now || !this.isToday()) {
                        return 'bg-blue-100 border border-blue-300 hover:bg-blue-200'; // Future programme
                    } else {
                        return 'bg-gray-100 border border-gray-300 hover:bg-gray-200'; // Past programme
                    }
                }
            }
        }
    </script>
</x-dynamic-component>
