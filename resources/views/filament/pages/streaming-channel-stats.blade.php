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
                        <p>Streams on Playlist: {{ $stat['activeStreams'] ?? 'N/A' }} / {{ $stat['maxStreams'] ?? 'N/A' }}</p>
                        <p>Output Codec: {{ $stat['codec'] ?? 'N/A' }}</p>

                        <h4 class="mt-2 font-semibold text-md">Source Video Details:</h4>
                        <p>Resolution: {{ $stat['resolution'] ?? 'N/A' }}</p>
                        <p>Full Codec Name: {{ $stat['video_codec_long_name'] ?? 'N/A' }}</p>
                        @if (!empty($stat['video_tags']) && is_array($stat['video_tags']))
                            <p>Video Tags:
                                @foreach($stat['video_tags'] as $key => $value)
                                    @if(!(strtoupper((string)$key) === 'VARIANT_BITRATE' && (string)$value === '0'))
                                        <span class="text-xs">{{ strtoupper((string)$key) }}: {{ is_array($value) ? json_encode($value) : $value }}; </span>
                                    @endif
                                @endforeach
                            </p>
                        @endif

                        <h4 class="mt-2 font-semibold text-md">Source Audio Details (First Stream):</h4>
                        <p>Codec: {{ $stat['audio_codec_name'] ?? 'N/A' }}</p>
                        <p>Profile: {{ $stat['audio_profile'] ?? 'N/A' }}</p>
                        <p>Channels: {{ $stat['audio_channels'] ?? 'N/A' }}</p>
                        <p>Layout: {{ $stat['audio_channel_layout'] ?? 'N/A' }}</p>
                        @if (!empty($stat['audio_tags']) && is_array($stat['audio_tags']))
                            <p>Audio Tags:
                                @foreach($stat['audio_tags'] as $key => $value)
                                    @if(!(strtoupper((string)$key) === 'VARIANT_BITRATE' && (string)$value === '0'))
                                        <span class="text-xs">{{ strtoupper((string)$key) }}: {{ is_array($value) ? json_encode($value) : $value }}; </span>
                                    @endif
                                @endforeach
                            </p>
                        @endif

                        <h4 class="mt-2 font-semibold text-md">Source Format Details:</h4>
                        @if (($stat['itemType'] ?? '') === 'Episode')
                            <p>Duration: {{ $stat['format_duration'] ?? 'N/A' }}</p>
                            <p>Size: {{ $stat['format_size'] ?? 'N/A' }}</p>
                            <p>Bitrate: {{ $stat['format_bit_rate'] ?? 'N/A' }}</p>
                        @endif
                        <p>Stream Count (in source): {{ $stat['format_nb_streams'] ?? 'N/A' }}</p>
                        @if (!empty($stat['format_tags']) && is_array($stat['format_tags']))
                            <p>Format Tags:
                                @foreach($stat['format_tags'] as $key => $value)
                                    @if(!(strtoupper((string)$key) === 'VARIANT_BITRATE' && (string)$value === '0'))
                                        <span class="text-xs">{{ strtoupper((string)$key) }}: {{ is_array($value) ? json_encode($value) : $value }}; </span>
                                    @endif
                                @endforeach
                            </p>
                        @endif

                        <p class="mt-2">Last Seen: <span class="relative-timestamp" data-timestamp="{{ $stat['lastSeen'] }}">{{ $stat['lastSeen'] ? 'Loading...' : 'N/A' }}</span></p>
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
    </script>
    @endpush
</x-filament-panels::page>
