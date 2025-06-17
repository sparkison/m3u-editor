<x-filament-panels::page>
    <div x-data="{ 
        refreshInterval: {{ $refreshInterval }},
        autoRefresh: true,
        intervalId: null
    }" x-init="
        intervalId = setInterval(() => {
            if (autoRefresh) {
                $wire.refreshData();
            }
        }, refreshInterval * 1000);
    " x-on:before-unload.window="clearInterval(intervalId)">
        
        <!-- Global Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <x-filament::card class="p-4">
                <div class="flex items-center">
                    <div class="p-2 bg-blue-100 dark:bg-blue-900 rounded-lg">
                        <svg class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4V2a1 1 0 011-1h8a1 1 0 011 1v2h4a1 1 0 110 2h-1v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6H3a1 1 0 110-2h4z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Streams</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $globalStats['active_streams'] ?? 0 }}</p>
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card class="p-4">
                <div class="flex items-center">
                    <div class="p-2 bg-green-100 dark:bg-green-900 rounded-lg">
                        <svg class="w-6 h-6 text-green-600 dark:text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Clients</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $globalStats['total_clients'] ?? 0 }}</p>
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card class="p-4">
                <div class="flex items-center">
                    <div class="p-2 bg-purple-100 dark:bg-purple-900 rounded-lg">
                        <svg class="w-6 h-6 text-purple-600 dark:text-purple-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Bandwidth</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">
                            @php
                                $totalBandwidth = $globalStats['total_bandwidth_kbps'] ?? 0;
                                echo $totalBandwidth > 1000 ? round($totalBandwidth / 1000, 1) . ' Mbps' : $totalBandwidth . ' kbps';
                            @endphp
                        </p>
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card class="p-4">
                <div class="flex items-center">
                    <div class="p-2 bg-yellow-100 dark:bg-yellow-900 rounded-lg">
                        <svg class="w-6 h-6 text-yellow-600 dark:text-yellow-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Avg Clients/Stream</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $globalStats['avg_clients_per_stream'] ?? '0.00' }}</p>
                    </div>
                </div>
            </x-filament::card>
        </div>

        <!-- Auto-refresh toggle -->
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <label class="flex items-center">
                    <input type="checkbox" x-model="autoRefresh" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400">Auto-refresh every {{ $refreshInterval }}s</span>
                </label>
                <span class="text-xs text-gray-500 dark:text-gray-400">Last updated: <span x-text="new Date().toLocaleTimeString()"></span></span>
            </div>
        </div>

        <!-- Streams List -->
        @if(empty($streams))
            <x-filament::card class="p-8 text-center">
                <div class="text-gray-500 dark:text-gray-400">
                    <svg class="mx-auto h-12 w-12 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    <p class="text-lg font-medium">No active streams</p>
                    <p class="text-sm">Shared streams will appear here when clients connect</p>
                </div>
            </x-filament::card>
        @else
            <div class="space-y-4">
                @foreach($streams as $stream)
                    <x-filament::card>
                        <div class="p-6" x-data="{ showClients: false, showDetails: false }">
                            <!-- Stream Header -->
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center space-x-4">
                                    <div class="flex-shrink-0">
                                        <div class="h-10 w-10 rounded-full flex items-center justify-center {{ 
                                            $stream['status'] === 'active' ? 'bg-green-100 text-green-600' : 
                                            ($stream['status'] === 'starting' ? 'bg-yellow-100 text-yellow-600' : 'bg-red-100 text-red-600') 
                                        }}">
                                            @if($stream['status'] === 'active')
                                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>
                                                </svg>
                                            @elseif($stream['status'] === 'starting')
                                                <svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                                </svg>
                                            @else
                                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8 7a1 1 0 012 0v6a1 1 0 11-2 0V7z" clip-rule="evenodd"></path>
                                                </svg>
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                            Stream {{ substr($stream['stream_id'], -8) }}
                                        </h3>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 font-mono">{{ $stream['source_url'] }}</p>
                                    </div>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        {{ $stream['format'] }}
                                    </span>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ 
                                        $stream['status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                        ($stream['status'] === 'starting' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') 
                                    }}">
                                        {{ ucfirst($stream['status']) }}
                                    </span>
                                </div>
                            </div>

                            <!-- Stream Stats Grid -->
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Clients</div>
                                    <div class="text-lg font-semibold text-gray-900 dark:text-white">{{ $stream['client_count'] }}</div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Bandwidth</div>
                                    <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                        {{ $stream['bandwidth_kbps'] > 1000 ? round($stream['bandwidth_kbps'] / 1000, 1) . ' Mbps' : $stream['bandwidth_kbps'] . ' kbps' }}
                                    </div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Data Transferred</div>
                                    <div class="text-lg font-semibold text-gray-900 dark:text-white">{{ $stream['bytes_transferred'] }}</div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Uptime</div>
                                    <div class="text-lg font-semibold text-gray-900 dark:text-white">{{ $stream['uptime'] }}</div>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2">
                                    <button @click="showClients = !showClients" 
                                            class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                        <span x-text="showClients ? 'Hide Clients' : 'Show Clients ({{ $stream['client_count'] }})'"></span>
                                    </button>
                                    <button @click="showDetails = !showDetails"
                                            class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                        <span x-text="showDetails ? 'Hide Details' : 'Show Details'"></span>
                                    </button>
                                </div>
                                
                                <div class="flex items-center space-x-2">
                                    <button wire:click="restartStream('{{ $stream['stream_id'] }}')" 
                                            class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-yellow-600 hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                                        Restart
                                    </button>
                                    <button wire:click="stopStream('{{ $stream['stream_id'] }}')" 
                                            class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                        Stop
                                    </button>
                                </div>
                            </div>

                            <!-- Clients List -->
                            <div x-show="showClients" x-transition class="mt-4 border-t pt-4" style="display: none;">
                                @if(empty($stream['clients']))
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No active clients</p>
                                @else
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                            <thead class="bg-gray-50 dark:bg-gray-800">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Client IP</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Connected</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Duration</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Data Received</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Bandwidth</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                                @foreach($stream['clients'] as $client)
                                                    <tr>
                                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white">{{ $client['ip'] }}</td>
                                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $client['connected_at'] }}</td>
                                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ gmdate('H:i:s', $client['duration']) }}</td>
                                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $client['bytes_received'] }}</td>
                                                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $client['bandwidth'] }}</td>
                                                        <td class="px-3 py-2 whitespace-nowrap">
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $client['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                                                {{ $client['is_active'] ? 'Active' : 'Inactive' }}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>

                            <!-- Stream Details -->
                            <div x-show="showDetails" x-transition class="mt-4 border-t pt-4" style="display: none;">
                                <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Stream ID:</span>
                                        <div class="font-mono text-xs break-all">{{ $stream['stream_id'] }}</div>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Health Status:</span>
                                        <div class="font-medium">{{ $stream['health_status'] ?? 'Unknown' }}</div>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Buffer Size:</span>
                                        <div class="font-medium">{{ $stream['buffer_size'] }}</div>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Started At:</span>
                                        <div class="font-medium">{{ $stream['started_at'] ?? 'N/A' }}</div>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Last Activity:</span>
                                        <div class="font-medium">{{ $stream['last_activity'] }}</div>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Process Status:</span>
                                        <div class="font-medium">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $stream['process_running'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                                {{ $stream['process_running'] ? 'Running' : 'Stopped' }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </x-filament::card>
                @endforeach
            </div>
        @endif

        <!-- System Stats Footer -->
        <div class="mt-8 border-t pt-6">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">System Information</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Memory Usage</div>
                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                        {{ $systemStats['memory_usage']['used'] ?? 'N/A' }} / {{ $systemStats['memory_usage']['total'] ?? 'N/A' }}
                        ({{ $systemStats['memory_usage']['percentage'] ?? 0 }}%)
                    </div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Disk Usage</div>
                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                        {{ $systemStats['disk_space']['used'] ?? 'N/A' }} / {{ $systemStats['disk_space']['total'] ?? 'N/A' }}
                        ({{ $systemStats['disk_space']['percentage'] ?? 0 }}%)
                    </div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Redis Status</div>
                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $systemStats['redis_connected'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                            {{ $systemStats['redis_connected'] ? 'Connected' : 'Disconnected' }}
                        </span>
                    </div>
                </div>
                <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
                    <div class="text-xs text-gray-500 dark:text-gray-400">System Uptime</div>
                    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $systemStats['uptime'] ?? 'N/A' }}</div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
