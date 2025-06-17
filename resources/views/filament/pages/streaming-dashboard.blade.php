<x-filament-panels::page>
    <div x-data="{ 
        refreshInterval: {{ $refreshInterval }},
        autoRefresh: true,
        intervalId: null
    }" x-init="
        if (refreshInterval > 0) {
            intervalId = setInterval(() => {
                if (autoRefresh) {
                    $wire.refreshData();
                }
            }, refreshInterval * 1000);
        }
    " x-on:before-unload.window="if(intervalId) clearInterval(intervalId)">

        <!-- Key Performance Indicators -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <!-- Active Streams Card -->
            <x-filament::card class="p-4">
                <div class="flex items-center">
                    <div class="p-2 bg-blue-100 dark:bg-blue-900 rounded-lg">
                        <svg class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Streams</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $performanceMetrics['active_streams'] ?? 0 }}</p>
                        <p class="text-xs text-gray-500">of {{ $performanceMetrics['total_streams'] ?? 0 }} total</p>
                    </div>
                </div>
            </x-filament::card>

            <!-- Connected Clients Card -->
            <x-filament::card class="p-4">
                <div class="flex items-center">
                    <div class="p-2 bg-green-100 dark:bg-green-900 rounded-lg">
                        <svg class="w-6 h-6 text-green-600 dark:text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Connected Clients</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $performanceMetrics['total_clients'] ?? 0 }}</p>
                        <p class="text-xs text-gray-500">Peak today: {{ $performanceMetrics['peak_clients_today'] ?? 0 }}</p>
                    </div>
                </div>
            </x-filament::card>

            <!-- Total Bandwidth Card -->
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
                                $bandwidth = $performanceMetrics['total_bandwidth_kbps'] ?? 0;
                                echo $bandwidth > 1000 ? round($bandwidth / 1000, 1) . ' Mbps' : $bandwidth . ' kbps';
                            @endphp
                        </p>
                        <p class="text-xs text-gray-500">
                            Peak: 
                            @php
                                $peak = $performanceMetrics['peak_bandwidth_today'] ?? 0;
                                echo $peak > 1000 ? round($peak / 1000, 1) . ' Mbps' : $peak . ' kbps';
                            @endphp
                        </p>
                    </div>
                </div>
            </x-filament::card>

            <!-- Efficiency Score Card -->
            <x-filament::card class="p-4">
                <div class="flex items-center">
                    <div class="p-2 bg-yellow-100 dark:bg-yellow-900 rounded-lg">
                        <svg class="w-6 h-6 text-yellow-600 dark:text-yellow-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Efficiency Score</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $performanceMetrics['efficiency_score'] ?? 0 }}%</p>
                        <p class="text-xs text-gray-500">{{ $performanceMetrics['avg_clients_per_stream'] ?? 0 }} avg clients/stream</p>
                    </div>
                </div>
            </x-filament::card>
        </div>

        <!-- Auto-refresh Control -->
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                @if($refreshInterval > 0)
                    <label class="flex items-center">
                        <input type="checkbox" x-model="autoRefresh" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        <span class="ml-2 text-sm text-gray-600 dark:text-gray-400">Auto-refresh every {{ $refreshInterval }}s</span>
                    </label>
                @endif
                <span class="text-xs text-gray-500 dark:text-gray-400">Last updated: <span x-text="new Date().toLocaleTimeString()"></span></span>
            </div>
        </div>

        <!-- Main Analytics Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Stream Performance Chart -->
            <x-filament::card class="p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Performance Overview (24h)</h3>
                <div class="space-y-4">
                    @if(!empty($historicalData['hourly']))
                        <canvas id="performanceChart" class="w-full" style="height: 300px;"></canvas>
                    @else
                        <div class="text-center text-gray-500 dark:text-gray-400 py-8">
                            <p>No performance data available yet</p>
                        </div>
                    @endif
                </div>
            </x-filament::card>

            <!-- Bandwidth Distribution -->
            <x-filament::card class="p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Bandwidth by Format</h3>
                <div class="space-y-4">
                    @forelse($bandwidthAnalytics['by_format'] ?? [] as $format => $data)
                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="flex items-center space-x-3">
                                <span class="px-2 py-1 text-xs font-medium rounded {{ $format === 'hls' ? 'bg-green-100 text-green-800' : ($format === 'dash' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800') }}">
                                    {{ strtoupper($format) }}
                                </span>
                                <span class="text-sm text-gray-600 dark:text-gray-400">{{ $data['streams'] }} streams</span>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                    {{ $data['bandwidth'] > 1000 ? round($data['bandwidth'] / 1000, 1) . ' Mbps' : $data['bandwidth'] . ' kbps' }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ $data['avg_per_stream'] > 1000 ? round($data['avg_per_stream'] / 1000, 1) . ' Mbps' : $data['avg_per_stream'] . ' kbps' }} avg
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-gray-500 dark:text-gray-400 py-8">
                            <p>No active streams to display</p>
                        </div>
                    @endforelse
                </div>
            </x-filament::card>
        </div>

        <!-- Detailed Statistics -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Top Streams by Clients -->
            <x-filament::card class="p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Top Streams by Clients</h3>
                <div class="space-y-3">
                    @forelse($streamStatistics['top_by_clients'] ?? [] as $stream)
                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                    {{ $stream->title ?: 'Stream ' . substr($stream->stream_id, -8) }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 font-mono">
                                    {{ substr($stream->stream_id, 0, 16) }}...
                                </p>
                            </div>
                            <div class="ml-4 text-right">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    {{ $stream->client_count }} clients
                                </span>
                                <p class="text-xs text-gray-500 mt-1">
                                    {{ $stream->bandwidth_kbps > 1000 ? round($stream->bandwidth_kbps / 1000, 1) . ' Mbps' : $stream->bandwidth_kbps . ' kbps' }}
                                </p>
                            </div>
                        </div>
                    @empty
                        <div class="text-center text-gray-500 dark:text-gray-400 py-4">
                            <p class="text-sm">No active streams</p>
                        </div>
                    @endforelse
                </div>
            </x-filament::card>

            <!-- Stream Health Distribution -->
            <x-filament::card class="p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Stream Health</h3>
                <div class="space-y-3">
                    @php
                        $healthColors = [
                            'healthy' => 'bg-green-100 text-green-800',
                            'warning' => 'bg-yellow-100 text-yellow-800',
                            'error' => 'bg-red-100 text-red-800',
                            'unknown' => 'bg-gray-100 text-gray-800'
                        ];
                    @endphp
                    @forelse($streamStatistics['health_distribution'] ?? [] as $status => $count)
                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <div class="flex items-center space-x-3">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium {{ $healthColors[$status] ?? $healthColors['unknown'] }}">
                                    {{ ucfirst($status) }}
                                </span>
                            </div>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $count }}</span>
                        </div>
                    @empty
                        <div class="text-center text-gray-500 dark:text-gray-400 py-4">
                            <p class="text-sm">No health data available</p>
                        </div>
                    @endforelse
                </div>
            </x-filament::card>

            <!-- System Performance -->
            <x-filament::card class="p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">System Performance</h3>
                <div class="space-y-3">
                    <!-- Memory Usage -->
                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Memory Usage</span>
                        <div class="text-right">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ $systemHealth['memory_usage']['percentage'] ?? 0 }}%
                            </span>
                            <div class="text-xs text-gray-500">
                                {{ $systemHealth['memory_usage']['used'] ?? 'N/A' }} / {{ $systemHealth['memory_usage']['total'] ?? 'N/A' }}
                            </div>
                        </div>
                    </div>

                    <!-- Disk Space -->
                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Disk Space</span>
                        <div class="text-right">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ $systemHealth['disk_space']['percentage'] ?? 0 }}%
                            </span>
                            <div class="text-xs text-gray-500">
                                {{ $systemHealth['disk_space']['used'] ?? 'N/A' }} / {{ $systemHealth['disk_space']['total'] ?? 'N/A' }}
                            </div>
                        </div>
                    </div>

                    <!-- Redis Status -->
                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                        <span class="text-sm text-gray-600 dark:text-gray-400">Redis Status</span>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ ($systemHealth['redis_connected'] ?? false) ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                            {{ ($systemHealth['redis_connected'] ?? false) ? 'Connected' : 'Disconnected' }}
                        </span>
                    </div>

                    <!-- Average Uptime -->
                    @if(!empty($streamStatistics['average_uptime']))
                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                            <span class="text-sm text-gray-600 dark:text-gray-400">Avg Stream Uptime</span>
                            <span class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ $streamStatistics['average_uptime'] }}
                            </span>
                        </div>
                    @endif
                </div>
            </x-filament::card>
        </div>

        <!-- Additional Insights -->
        @if(!empty($streamStatistics['longest_running']))
        <x-filament::card class="p-6">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Longest Running Stream</h3>
            <div class="bg-gradient-to-r from-blue-50 to-purple-50 dark:from-blue-900/20 dark:to-purple-900/20 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            {{ $streamStatistics['longest_running']['title'] ?: 'Stream ' . substr($streamStatistics['longest_running']['stream_id'], -8) }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 font-mono">
                            {{ $streamStatistics['longest_running']['stream_id'] }}
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-lg font-bold text-gray-900 dark:text-white">
                            {{ $streamStatistics['longest_running']['uptime'] }}
                        </p>
                        <p class="text-xs text-gray-500">Running continuously</p>
                    </div>
                </div>
            </div>
        </x-filament::card>
        @endif
    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Performance Chart
            const performanceCtx = document.getElementById('performanceChart');
            if (performanceCtx) {
                const hourlyData = @json($historicalData['hourly'] ?? []);
                
                const labels = hourlyData.map(item => {
                    const date = new Date(item.hour);
                    return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                });
                
                const clientData = hourlyData.map(item => parseFloat(item.avg_clients) || 0);
                const bandwidthData = hourlyData.map(item => (parseFloat(item.avg_bandwidth) || 0) / 1000); // Convert to Mbps
                
                new Chart(performanceCtx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Avg Clients',
                            data: clientData,
                            borderColor: '#10B981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            yAxisID: 'y',
                            tension: 0.4
                        }, {
                            label: 'Bandwidth (Mbps)',
                            data: bandwidthData,
                            borderColor: '#8B5CF6',
                            backgroundColor: 'rgba(139, 92, 246, 0.1)',
                            yAxisID: 'y1',
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Clients'
                                },
                                beginAtZero: true
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Bandwidth (Mbps)'
                                },
                                grid: {
                                    drawOnChartArea: false,
                                },
                                beginAtZero: true
                            }
                        },
                        interaction: {
                            intersect: false,
                            mode: 'index',
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                            }
                        }
                    }
                });
            }
        });
    </script>
    @endpush
</x-filament-panels::page>
