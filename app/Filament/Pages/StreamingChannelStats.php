<?php

namespace App\Filament\Pages;

use App\Models\Channel;
use App\Models\Playlist;
use App\Services\ProxyService;
use Carbon\Carbon;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StreamingChannelStats extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-video-camera';
    protected static ?string $navigationLabel = 'Streaming Stats';
    protected static ?string $title = 'Active Streaming Channel Statistics';
    protected static ?string $navigationGroup = 'Tools';
    protected static ?int $navigationSort = 10; // Adjust as needed

    protected static string $view = 'filament.pages.streaming-channel-stats';

    // We can add properties to hold data later
    public $statsData = [];

    public function mount(): void
    {
        // Data fetching logic will go here in a later step
        $this->statsData = $this->getStatsData();
    }

    protected function getStatsData(): array
    {
        $stats = [];
        // Fetch active channel stream IDs from Redis
        $activeChannelIds = Redis::smembers('hls:active_channel_ids');
        Log::info('Active HLS Channel IDs from Redis: ', $activeChannelIds ?: []);

        foreach ($activeChannelIds as $originalChannelId) {
            $actualStreamingChannelId = Cache::get("hls:stream_mapping:channel:{$originalChannelId}") ?: $originalChannelId;
            $channel = Channel::find($actualStreamingChannelId);

            // Get last seen for original channel ID - needs to be available even if channel or playlist is missing later
            $lastSeenTimestamp = Redis::get("hls:channel_last_seen:{$originalChannelId}");
            $lastSeenDisplay = $lastSeenTimestamp ? Carbon::createFromTimestamp($lastSeenTimestamp)->format('Y-m-d H:i:s') : 'N/A';

            if (!$channel) {
                Log::warning("StreamingChannelStats: Channel not found for ID {$actualStreamingChannelId} (original ID: {$originalChannelId})");
                // Add a basic entry if channel is not found
                $stats[] = [
                    'channelName' => "ID: {$actualStreamingChannelId} (Not Found)",
                    'playlistName' => 'N/A',
                    'activeStreams' => 'N/A',
                    'maxStreams' => 'N/A',
                    'codec' => 'N/A', // Combined field will be N/A
                    // No 'hwAccel' key
                    'resolution' => 'N/A',
                    'lastSeen' => $lastSeenDisplay,
                    'isBadSource' => false, // Cannot determine bad source without channel/playlist
                ];
                continue;
            }

            $playlist = $channel->playlist;
            $isBadSource = false; // Default

            if (!$playlist) {
                Log::warning("StreamingChannelStats: Playlist not found for Channel ID {$channel->id}");
                $stats[] = [
                    'channelName' => $channel->title_custom ?? $channel->title,
                    'playlistName' => 'N/A (Playlist missing)',
                    'activeStreams' => 'N/A',
                    'maxStreams' => 'N/A',
                    'codec' => 'N/A', // No codec info if playlist (and thus settings for it) is missing
                    // No 'hwAccel' key
                    'resolution' => 'N/A',
                    'lastSeen' => $lastSeenDisplay,
                    'isBadSource' => $isBadSource,
                ];
                continue;
            }

            // Check bad source using actual streaming channel ID and its playlist
            $badSourceCacheKey = "mfp:bad_source:{$channel->id}:{$playlist->id}";
            $isBadSource = Redis::exists($badSourceCacheKey);

            $activeStreamsOnPlaylist = Redis::get("active_streams:{$playlist->id}") ?? 0;
            $maxStreamsOnPlaylist = $playlist->available_streams ?? 'N/A';

            // Format maxStreams
            $maxStreamsDisplay = ($maxStreamsOnPlaylist == 0 && is_numeric($maxStreamsOnPlaylist)) ? '∞' : $maxStreamsOnPlaylist;
            if ($maxStreamsOnPlaylist === 'N/A') { // Handles if available_streams was null
                $maxStreamsDisplay = 'N/A';
            }

            // Determine codec and HW Accel
            $settings = ProxyService::getStreamSettings();
            $hwAccelMethod = $settings['hardware_acceleration_method'] ?? 'none';
            $finalVideoCodec = ProxyService::determineVideoCodec(
                config('proxy.ffmpeg_codec_video', null),
                $settings['ffmpeg_codec_video'] ?? 'copy'
            );
            $outputVideoCodec = $finalVideoCodec; // Default

            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($hwAccelMethod === 'vaapi' || $isVaapiCodec) {
                $outputVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi';
            } elseif ($hwAccelMethod === 'qsv' || $isQsvCodec) {
                $outputVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv';
            }

            // New combined codec and HW accel string logic
            $formattedCodecString = 'N/A';

            if ($outputVideoCodec === 'copy') {
                $formattedCodecString = 'copy (source)';
            } elseif ($outputVideoCodec !== 'N/A' && $outputVideoCodec !== null) {
                $baseCodec = $outputVideoCodec;
                // Remove _qsv or _vaapi from baseCodec if present, as HW status will be added
                $baseCodec = str_replace(['_qsv', '_vaapi'], '', $baseCodec);

                $hwStatus = 'HW None';
                if ($hwAccelMethod === 'qsv') {
                    $hwStatus = 'HW QSV';
                } elseif ($hwAccelMethod === 'vaapi') {
                    $hwStatus = 'HW VAAPI';
                }

                // If the outputVideoCodec *already* implies a specific hardware (e.g., h264_qsv),
                // and the hwAccelMethod matches, ensure the status reflects that.
                if (str_contains($outputVideoCodec, '_qsv') && $hwAccelMethod === 'qsv') {
                     $baseCodec = str_replace('_qsv', '', $outputVideoCodec);
                     $hwStatus = 'HW QSV';
                } elseif (str_contains($outputVideoCodec, '_vaapi') && $hwAccelMethod === 'vaapi') {
                     $baseCodec = str_replace('_vaapi', '', $outputVideoCodec);
                     $hwStatus = 'HW VAAPI';
                }
                $formattedCodecString = "{$baseCodec} ({$hwStatus})";
            }
            // If $channel was null previously, this logic path won't be hit.
            // If $outputVideoCodec determination resulted in 'N/A', $formattedCodecString remains 'N/A'.

            $stats[] = [
                'channelName' => $channel->title_custom ?? $channel->title,
                'playlistName' => $playlist->name,
                'activeStreams' => $activeStreamsOnPlaylist,
                'maxStreams' => $maxStreamsDisplay,
                'codec' => $formattedCodecString, // Use the new combined string
                // 'hwAccel' key is now removed
                'resolution' => 'N/A',
                'lastSeen' => $lastSeenDisplay,
                'isBadSource' => $isBadSource,
            ];
        }
        Log::info('Processed statsData: ', $stats);
        return $stats;
    }
}
