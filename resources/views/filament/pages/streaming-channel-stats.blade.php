<x-filament-panels::page>
    <div class="space-y-4">
        @if (empty($statsData))
            <p>No active streams or data available currently.</p>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($statsData as $stat)
                    <x-filament::card>
                        <h3 class="text-lg font-semibold">{{ $stat['channelName'] ?? 'N/A' }}</h3>
                        <p>Playlist: {{ $stat['playlistName'] ?? 'N/A' }}</p>
                        <p>Streams: {{ $stat['activeStreams'] ?? 'N/A' }} / {{ $stat['maxStreams'] ?? 'N/A' }}</p>
                        <p>Codec: {{ $stat['codec'] ?? 'N/A' }}</p>
                        <p>Resolution: {{ $stat['resolution'] ?? 'N/A' }}</p>
                        <p>Last Seen: {{ $stat['lastSeen'] ?? 'N/A' }}</p>
                        <p>Bad Source: {{ ($stat['isBadSource'] ?? false) ? 'Yes' : 'No' }}</p>
                    </x-filament::card>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
