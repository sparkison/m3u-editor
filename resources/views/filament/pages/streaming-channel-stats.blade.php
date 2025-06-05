<x-filament-panels::page>
    <div class="space-y-4">
        @if (empty($statsData))
            <p>No active streams or data available currently.</p>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($statsData as $stat)
                    <x-filament::card>
                        <h3 class="text-lg font-semibold">{{ $stat['itemName'] ?? 'N/A' }} ({{ $stat['itemType'] ?? 'N/A' }})</h3>
                        <p>Playlist: {{ $stat['playlistName'] ?? 'N/A' }}</p>
                        <p>Streams: {{ $stat['activeStreams'] ?? 'N/A' }} / {{ $stat['maxStreams'] ?? 'N/A' }}</p>
                        <p>Codec: {{ $stat['codec'] ?? 'N/A' }}</p>
                        <p>Resolution: {{ $stat['resolution'] ?? 'N/A' }}</p>
                        <p>Last Seen: <span class="relative-timestamp" data-timestamp="{{ $stat['lastSeen'] }}">{{ $stat['lastSeen'] ? 'Loading...' : 'N/A' }}</span></p>
                        @if ($stat['isBadSource'] ?? false)
                            <p class="text-red-500">Bad Source: Yes</p>
                        @endif
                    </x-filament::card>
                @endforeach
            </div>
        @endif
    </div>

    @push('scripts')
    <script>
        function timeDifference(current, previous) {
            const msPerMinute = 60 * 1000;
            const msPerHour = msPerMinute * 60;
            const msPerDay = msPerHour * 24;
            const msPerMonth = msPerDay * 30; // Approx
            const msPerYear = msPerDay * 365; // Approx

            const elapsed = current - previous;

            if (elapsed < msPerMinute) {
                 return Math.round(elapsed/1000) + ' seconds ago';
            } else if (elapsed < msPerHour) {
                 return Math.round(elapsed/msPerMinute) + ' minutes ago';
            } else if (elapsed < msPerDay ) {
                 return Math.round(elapsed/msPerHour ) + ' hours ago';
            } else if (elapsed < msPerMonth) {
                return 'approximately ' + Math.round(elapsed/msPerDay) + ' days ago';
            } else if (elapsed < msPerYear) {
                return 'approximately ' + Math.round(elapsed/msPerMonth) + ' months ago';
            } else {
                return 'approximately ' + Math.round(elapsed/msPerYear ) + ' years ago';
            }
        }

        function updateRelativeTimes() {
            const elements = document.querySelectorAll('.relative-timestamp');
            elements.forEach(function(element) {
                const timestamp = parseInt(element.getAttribute('data-timestamp'), 10);
                if (timestamp && !isNaN(timestamp)) {
                    // Convert Unix timestamp (seconds) to milliseconds for JavaScript Date
                    element.textContent = timeDifference(Date.now(), timestamp * 1000);
                } else {
                    element.textContent = 'N/A'; // If timestamp is invalid or null
                }
            });
        }

        // Update on initial load
        document.addEventListener('DOMContentLoaded', function() {
            updateRelativeTimes();
            // Update every 30 seconds
            setInterval(updateRelativeTimes, 30000);
        });
        // It's also good practice to call this if Livewire updates part of the page,
        // for example, by listening to Livewire hooks if this page uses Livewire polling
        // for other data, which might re-render these spans.
        // However, Filament's default page polling might re-render the whole component,
        // re-initializing these. If this script is within the polled component,
        // `DOMContentLoaded` might not be the best trigger after initial load.
        // For now, this setup will work for initial load and basic interval.
        // If Filament's polling re-renders this part, the 'Loading...' will briefly show
        // then JS will update it.
    </script>
    @endpush
</x-filament-panels::page>
