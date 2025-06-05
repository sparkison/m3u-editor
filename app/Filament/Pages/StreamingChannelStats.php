<?php

namespace App\Filament\Pages;

use App\Models\Channel;
use App\Models\Episode; // Added
use App\Models\Playlist;
use App\Services\ProxyService;
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
        $activeItems = [];

        $activeChannelIds = Redis::smembers('hls:active_channel_ids');
        Log::info('Active HLS Channel IDs from Redis: ', $activeChannelIds ?: []);
        foreach ($activeChannelIds as $id) {
            $activeItems[] = ['id' => $id, 'type' => 'channel'];
        }

        $activeEpisodeIds = Redis::smembers('hls:active_episode_ids');
        Log::info('Active HLS Episode IDs from Redis: ', $activeEpisodeIds ?: []);
        foreach ($activeEpisodeIds as $id) {
            $activeItems[] = ['id' => $id, 'type' => 'episode'];
        }

        if (empty($activeItems)) {
            return [];
        }

        foreach ($activeItems as $item) {
            $originalModelId = $item['id'];
            $modelType = $item['type']; // 'channel' or 'episode'
            $model = null;
            $itemName = 'Unknown Item';

            // Resolve actual streaming ID (failover)
            $actualStreamingModelId = Cache::get("hls:stream_mapping:{$modelType}:{$originalModelId}");
            if (!$actualStreamingModelId) {
                $actualStreamingModelId = $originalModelId;
            }

            if ($modelType === 'channel') {
                $model = Channel::find($actualStreamingModelId);
                $itemName = $model ? ($model->title_custom ?? $model->title) : "Channel ID: {$actualStreamingModelId} (Not Found)";
            } elseif ($modelType === 'episode') {
                $model = Episode::find($actualStreamingModelId);
                if ($model && $model->series) { // Ensure model and series relation exists
                    $itemName = $model->series->title . " - S" . $model->season_num . "E" . $model->episode_num . " - " . $model->title;
                } elseif ($model) { // Model exists but no series
                     $itemName = "Ep. " . $model->title;
                } else { // Model not found
                    $itemName = "Episode ID: {$actualStreamingModelId} (Not Found)";
                }
            }

            // Last seen should be fetched regardless of model found, using original ID and type
            $lastSeenTimestamp = Redis::get("hls:{$modelType}_last_seen:{$originalModelId}");
            $lastSeenValue = $lastSeenTimestamp ?: null;

            if (!$model) {
                Log::warning("StreamingChannelStats: Model not found for type {$modelType}, ID {$actualStreamingModelId} (original ID: {$originalModelId})");
                $stats[] = [
                    'itemName' => $itemName,
                    'itemType' => ucfirst($modelType),
                    'playlistName' => 'N/A (Model missing)',
                    'activeStreams' => 'N/A',
                    'maxStreams' => 'N/A',
                    'codec' => 'N/A',
                    'resolution' => 'N/A',
                    'lastSeen' => $lastSeenValue, // lastSeen is available
                    'isBadSource' => false, // Cannot determine without model/playlist
                ];
                continue;
            }

            $playlist = $model->playlist;

            $activeStreamsOnPlaylist = 'N/A';
            $maxStreamsDisplay = 'N/A';
            $isBadSource = false; // Default

            if ($playlist) {
                $activeStreamsOnPlaylist = Redis::get("active_streams:{$playlist->id}") ?? 0;
                $maxStreamsOnPlaylist = $playlist->available_streams ?? 'N/A';
                $maxStreamsDisplay = ($maxStreamsOnPlaylist == 0 && is_numeric($maxStreamsOnPlaylist)) ? '∞' : $maxStreamsOnPlaylist;
                if ($maxStreamsOnPlaylist === 'N/A') $maxStreamsDisplay = 'N/A';

                $badSourceCacheKey = "mfp:bad_source:{$model->id}:{$playlist->id}"; // Use $model->id
                $isBadSource = Redis::exists($badSourceCacheKey);
            } else {
                 Log::warning("StreamingChannelStats: Playlist not found for {$modelType} ID {$model->id}");
            }

            // Codec and HW Accel determination
            $settings = ProxyService::getStreamSettings();
            $hwAccelMethod = $settings['hardware_acceleration_method'] ?? 'none';
            $finalVideoCodec = ProxyService::determineVideoCodec(
                config('proxy.ffmpeg_codec_video', null),
                $settings['ffmpeg_codec_video'] ?? 'copy'
            );
            $outputVideoCodec = $finalVideoCodec;

            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($hwAccelMethod === 'vaapi' || $isVaapiCodec) {
                $outputVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi';
            } elseif ($hwAccelMethod === 'qsv' || $isQsvCodec) {
                $outputVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv';
            }

            $formattedCodecString = 'N/A';
            if ($outputVideoCodec === 'copy') {
                $formattedCodecString = 'copy (source)';
            } elseif ($outputVideoCodec !== 'N/A' && $outputVideoCodec !== null) {
                $baseCodec = str_replace(['_qsv', '_vaapi'], '', $outputVideoCodec);
                $hwStatus = 'HW None';
                if ($hwAccelMethod === 'qsv') $hwStatus = 'HW QSV';
                elseif ($hwAccelMethod === 'vaapi') $hwStatus = 'HW VAAPI';
                if (str_contains($outputVideoCodec, '_qsv') && $hwAccelMethod === 'qsv') {
                     $baseCodec = str_replace('_qsv', '', $outputVideoCodec);
                     $hwStatus = 'HW QSV';
                } elseif (str_contains($outputVideoCodec, '_vaapi') && $hwAccelMethod === 'vaapi') {
                     $baseCodec = str_replace('_vaapi', '', $outputVideoCodec);
                     $hwStatus = 'HW VAAPI';
                }
                $formattedCodecString = "{$baseCodec} ({$hwStatus})";
            }


            $stats[] = [
                'itemName' => $itemName,
                'itemType' => ucfirst($modelType),
                'playlistName' => $playlist ? $playlist->name : 'N/A (Playlist missing)',
                'activeStreams' => $activeStreamsOnPlaylist,
                'maxStreams' => $maxStreamsDisplay,
                'codec' => $formattedCodecString,
                'resolution' => 'N/A', // Still deferred
                'lastSeen' => $lastSeenValue,
                'isBadSource' => $isBadSource,
            ];
        }
        Log::info('Processed statsData (including episodes): ', $stats);
        return $stats;
    }
}
