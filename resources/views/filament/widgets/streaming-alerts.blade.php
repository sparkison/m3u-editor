<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between">
                <span>System Alerts & Notifications</span>
                <div class="flex items-center space-x-2">
                    @php
                        $totalAlerts = count($alerts ?? []) + count($systemHealth ?? []) + count($streamIssues ?? []) + count($performanceWarnings ?? []);
                        $hasErrors = collect($alerts ?? [])->concat($systemHealth ?? [])->contains('type', 'error');
                        $hasWarnings = collect($alerts ?? [])->concat($systemHealth ?? [])->contains('type', 'warning');
                    @endphp
                    
                    @if($totalAlerts > 0)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $hasErrors ? 'bg-red-100 text-red-800' : ($hasWarnings ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800') }}">
                            {{ $totalAlerts }} {{ $totalAlerts === 1 ? 'alert' : 'alerts' }}
                        </span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                            </svg>
                            All systems normal
                        </span>
                    @endif
                </div>
            </div>
        </x-slot>

        <div class="space-y-4">
            @if($totalAlerts === 0)
                <!-- No Alerts State -->
                <div class="text-center py-8">
                    <div class="mx-auto h-12 w-12 text-green-500 mb-4">
                        <svg fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                    </div>
                    <h3 class="text-sm font-medium text-gray-900 dark:text-white">All Systems Operational</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">No alerts or issues detected</p>
                </div>
            @else
                <!-- Active Alerts Section -->
                @if(!empty($alerts))
                    <div class="space-y-3">
                        @foreach($alerts as $alert)
                            <div class="flex items-start space-x-3 p-4 rounded-lg border-l-4 {{ 
                                $alert['type'] === 'error' ? 'bg-red-50 border-red-500 dark:bg-red-900/20' : 
                                ($alert['type'] === 'warning' ? 'bg-yellow-50 border-yellow-500 dark:bg-yellow-900/20' : 
                                'bg-blue-50 border-blue-500 dark:bg-blue-900/20') 
                            }}">
                                <div class="flex-shrink-0">
                                    @if($alert['type'] === 'error')
                                        <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path>
                                        </svg>
                                    @elseif($alert['type'] === 'warning')
                                        <svg class="w-5 h-5 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                        </svg>
                                    @else
                                        <svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                                        </svg>
                                    @endif
                                </div>
                                
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">{{ $alert['title'] }}</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">{{ $alert['message'] }}</p>
                                    <div class="flex items-center justify-between mt-2">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $alert['timestamp']->diffForHumans() }}
                                        </span>
                                        @if(isset($alert['action_url']))
                                            <a href="{{ $alert['action_url'] }}" class="text-xs font-medium {{ 
                                                $alert['type'] === 'error' ? 'text-red-600 hover:text-red-500' : 
                                                ($alert['type'] === 'warning' ? 'text-yellow-600 hover:text-yellow-500' : 
                                                'text-blue-600 hover:text-blue-500') 
                                            }}">
                                                {{ $alert['action'] }} →
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <!-- System Health Alerts -->
                @if(!empty($systemHealth))
                    <div class="space-y-2">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">System Health Issues</h4>
                        @foreach($systemHealth as $health)
                            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                <div class="flex items-center space-x-2">
                                    <div class="w-2 h-2 rounded-full {{ 
                                        $health['type'] === 'error' ? 'bg-red-500' : 
                                        ($health['type'] === 'warning' ? 'bg-yellow-500' : 'bg-blue-500') 
                                    }}"></div>
                                    <span class="text-sm text-gray-900 dark:text-white">{{ $health['title'] }}</span>
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $health['message'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                <!-- Stream Issues -->
                @if(!empty($streamIssues))
                    <div class="space-y-2">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Stream Issues</h4>
                        @foreach($streamIssues as $issue)
                            <div class="flex items-center justify-between p-3 bg-red-50 dark:bg-red-900/20 rounded-lg">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-2">
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $issue['title'] }}</span>
                                        <span class="px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-600 dark:text-gray-300 rounded">
                                            {{ substr($issue['stream_id'], -8) }}
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-600 dark:text-gray-300 mt-1">{{ $issue['issue'] }}: {{ $issue['details'] }}</p>
                                </div>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium {{ 
                                    $issue['severity'] === 'high' ? 'bg-red-100 text-red-800' : 
                                    ($issue['severity'] === 'medium' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800') 
                                }}">
                                    {{ ucfirst($issue['severity']) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif

                <!-- Performance Warnings -->
                @if(!empty($performanceWarnings))
                    <div class="space-y-2">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Performance Warnings</h4>
                        @foreach($performanceWarnings as $warning)
                            <div class="flex items-center justify-between p-3 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg">
                                <div class="flex items-center space-x-2">
                                    <svg class="w-4 h-4 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"></path>
                                    </svg>
                                    <span class="text-sm text-gray-900 dark:text-white">{{ $warning['title'] }}</span>
                                </div>
                                <span class="text-xs text-gray-600 dark:text-gray-300">{{ $warning['message'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
