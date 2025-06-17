<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Status Overview --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Active Streams</div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $stats['active_streams'] }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Requests</div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($stats['total_requests']) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Error Count</div>
                <div class="text-2xl font-bold text-red-600">{{ number_format($stats['error_count']) }}</div>
            </div>
            <div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Success Rate</div>
                <div class="text-2xl font-bold text-green-600">{{ $stats['success_rate'] }}%</div>
            </div>
        </div>

        {{-- Configuration Form --}}
        <form wire:submit="save">
            {{ $this->form }}
            
            <div class="flex gap-4 mt-6">
                <x-filament::button type="submit">
                    Save Configuration
                </x-filament::button>
                
                <x-filament::button 
                    type="button" 
                    color="secondary"
                    wire:click="testMicroservice"
                    wire:loading.attr="disabled"
                >
                    <x-filament::loading-indicator wire:loading wire:target="testMicroservice" class="h-4 w-4" />
                    Test Microservice Connection
                </x-filament::button>
            </div>
        </form>

        {{-- API Endpoints Documentation --}}
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow mt-6">
            <h3 class="text-lg font-semibold mb-4">MediaFlow Proxy API Endpoints</h3>
            
            <div class="space-y-4">
                <div class="border dark:border-gray-700 rounded p-4">
                    <div class="font-mono text-sm font-medium text-blue-600">GET /api/mediaflow/proxy/hls/manifest.m3u8</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        Proxy HLS manifest files with processing and URL rewriting
                    </div>
                    <div class="text-xs text-gray-500 mt-2">
                        Parameters: <code>d</code> (destination URL), <code>force_playlist_proxy</code>, <code>key_url</code>, <code>h_*</code> (headers)
                    </div>
                </div>
                
                <div class="border dark:border-gray-700 rounded p-4">
                    <div class="font-mono text-sm font-medium text-blue-600">GET /api/mediaflow/proxy/stream</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        Proxy generic video/audio streams with header forwarding
                    </div>
                    <div class="text-xs text-gray-500 mt-2">
                        Parameters: <code>d</code> (destination URL), <code>h_*</code> (headers)
                    </div>
                </div>
                
                <div class="border dark:border-gray-700 rounded p-4">
                    <div class="font-mono text-sm font-medium text-blue-600">GET /api/mediaflow/proxy/channel/{id}/stream</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        Proxy channel streams with automatic failover support
                    </div>
                    <div class="text-xs text-gray-500 mt-2">
                        Includes stream counting and playlist limit enforcement
                    </div>
                </div>
                
                <div class="border dark:border-gray-700 rounded p-4">
                    <div class="font-mono text-sm font-medium text-blue-600">GET /api/mediaflow/proxy/episode/{id}/stream</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        Proxy episode streams with automatic failover support
                    </div>
                    <div class="text-xs text-gray-500 mt-2">
                        Includes stream counting and playlist limit enforcement
                    </div>
                </div>
                
                <div class="border dark:border-gray-700 rounded p-4">
                    <div class="font-mono text-sm font-medium text-blue-600">GET /api/mediaflow/proxy/ip</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        Get public IP address (compatible with MediaFlow proxy)
                    </div>
                </div>
                
                <div class="border dark:border-gray-700 rounded p-4">
                    <div class="font-mono text-sm font-medium text-blue-600">GET /api/mediaflow/proxy/health</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                        Health check endpoint for monitoring
                    </div>
                </div>
            </div>
        </div>

        {{-- Usage Examples --}}
        <div class="bg-white dark:bg-gray-800 p-6 rounded-lg shadow mt-6">
            <h3 class="text-lg font-semibold mb-4">Usage Examples</h3>
            
            <div class="space-y-4">
                <div>
                    <h4 class="font-medium mb-2">Basic HLS Stream Proxy:</h4>
                    <code class="block bg-gray-100 dark:bg-gray-900 p-3 rounded text-sm">
                        {{ url('/api/mediaflow/proxy/hls/manifest.m3u8') }}?d={{ urlencode('https://example.com/stream.m3u8') }}
                    </code>
                </div>
                
                <div>
                    <h4 class="font-medium mb-2">Stream with Custom Headers:</h4>
                    <code class="block bg-gray-100 dark:bg-gray-900 p-3 rounded text-sm">
                        {{ url('/api/mediaflow/proxy/stream') }}?d={{ urlencode('https://download.blender.org/peach/bigbuckbunny_movies/BigBuckBunny_640x360.m4v') }}&h_user-agent=CustomClient&h_referer=https://blender.org
                    </code>
                </div>
                
                <div>
                    <h4 class="font-medium mb-2">Channel with Failover:</h4>
                    <code class="block bg-gray-100 dark:bg-gray-900 p-3 rounded text-sm">
                        {{ url('/api/mediaflow/proxy/channel/123/stream') }}
                    </code>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
