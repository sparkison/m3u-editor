<?php

namespace App\Filament\Pages;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist; // Should be present if Playlist model is used
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

    public $statsData = [];

    public function mount(): void
    {
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
                if ($model && $model->series) {
                    $itemName = $model->series->title . " - S" . $model->season_num . "E" . $model->episode_num . " - " . $model->title;
                } elseif ($model) {
                     $itemName = "Ep. " . $model->title;
                } else {
                    $itemName = "Episode ID: {$actualStreamingModelId} (Not Found)";
                }
            }

            // Fetch process start time
            $startTimeKey = "streaminfo:starttime:{$modelType}:{$actualStreamingModelId}";
            $processStartTime = Redis::get($startTimeKey) ?: null;

            // Fetch detailed stream information
            $detailsCacheKey = "streaminfo:details:{$modelType}:{$actualStreamingModelId}";
            $detailsJson = Redis::get($detailsCacheKey);
            $streamDetails = $detailsJson ? json_decode($detailsJson, true) : [];

            // Video Details
            $videoInfo = $streamDetails['video'] ?? [];
            $resolutionDisplay = ($videoInfo['width'] ?? 'N/A') . 'x' . ($videoInfo['height'] ?? 'N/A');
            if ($resolutionDisplay === 'N/AxN/A') $resolutionDisplay = 'N/A';
            $codecLongName = $videoInfo['codec_long_name'] ?? 'N/A';
            $colorRange = $videoInfo['color_range'] ?? 'N/A';
            $colorSpace = $videoInfo['color_space'] ?? 'N/A';
            $colorTransfer = $videoInfo['color_transfer'] ?? 'N/A';
            $colorPrimaries = $videoInfo['color_primaries'] ?? 'N/A';
            $videoTags = $videoInfo['tags'] ?? [];

            // Audio Details
            $audioInfo = $streamDetails['audio'] ?? [];
            $audioCodec = $audioInfo['codec_name'] ?? 'N/A';
            $audioProfile = $audioInfo['profile'] ?? 'N/A';
            $audioChannels = $audioInfo['channels'] ?? 'N/A';
            $audioChannelLayout = $audioInfo['channel_layout'] ?? 'N/A';
            $audioTags = $audioInfo['tags'] ?? [];

            // Format Details
            $formatInfo = $streamDetails['format'] ?? [];
            $formatDuration = ($formatInfo['duration'] ?? null) ? gmdate("H:i:s", (int)$formatInfo['duration']) : 'N/A';
            $formatSize = ($formatInfo['size'] ?? null) ? round($formatInfo['size'] / (1024*1024), 2) . ' MB' : 'N/A';
            $formatBitRate = ($formatInfo['bit_rate'] ?? null) ? round($formatInfo['bit_rate'] / 1000, 0) . ' kbps' : 'N/A';
            $formatNbStreams = $formatInfo['nb_streams'] ?? 'N/A';
            $formatTags = $formatInfo['tags'] ?? [];

            if (!$model) {
                Log::warning("StreamingChannelStats: Model not found for type {$modelType}, ID {$actualStreamingModelId} (original ID: {$originalModelId})");
                $stats[] = [
                    'itemName' => $itemName,
                    'itemType' => ucfirst($modelType),
                    'playlistName' => 'N/A (Model missing)',
                    'activeStreams' => 'N/A', 'maxStreams' => 'N/A', 'codec' => 'N/A',
                    'resolution' => $resolutionDisplay, // Will be N/A if model not found, but details might have been fetched if ID existed
                    'video_codec_long_name' => $codecLongName, 'video_color_range' => $colorRange,
                    'video_color_space' => $colorSpace, 'video_color_transfer' => $colorTransfer, 'video_color_primaries' => $colorPrimaries,
                    'video_tags' => $videoTags, 'audio_codec_name' => $audioCodec, 'audio_profile' => $audioProfile,
                    'audio_channels' => $audioChannels, 'audio_channel_layout' => $audioChannelLayout, 'audio_tags' => $audioTags,
                    'format_duration' => $formatDuration, 'format_size' => $formatSize, 'format_bit_rate' => $formatBitRate,
                    'format_nb_streams' => $formatNbStreams, 'format_tags' => $formatTags,
                    'processStartTime' => $processStartTime,
                    'isBadSource' => false,
                ];
                continue;
            }

            $playlist = $model->playlist;
            $activeStreamsOnPlaylist = 'N/A';
            $maxStreamsDisplay = 'N/A';
            $isBadSource = false;

            if ($playlist) {
                $activeStreamsOnPlaylist = Redis::get("active_streams:{$playlist->id}") ?? 0;
                $maxStreamsOnPlaylist = $playlist->available_streams ?? 'N/A';
                $maxStreamsDisplay = ($maxStreamsOnPlaylist == 0 && is_numeric($maxStreamsOnPlaylist)) ? '∞' : $maxStreamsOnPlaylist;
                if ($maxStreamsOnPlaylist === 'N/A') $maxStreamsDisplay = 'N/A';
                $badSourceCacheKey = "mfp:bad_source:{$model->id}:{$playlist->id}";
                $isBadSource = Redis::exists($badSourceCacheKey);
            } else {
                 Log::warning("StreamingChannelStats: Playlist not found for {$modelType} ID {$model->id}");
            }

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
                'resolution' => $resolutionDisplay,
                'video_codec_long_name' => $codecLongName,
                'video_color_range' => $colorRange,
                'video_color_space' => $colorSpace,
                'video_color_transfer' => $colorTransfer,
                'video_color_primaries' => $colorPrimaries,
                'video_tags' => $videoTags,
                'audio_codec_name' => $audioCodec,
                'audio_profile' => $audioProfile,
                'audio_channels' => $audioChannels,
                'audio_channel_layout' => $audioChannelLayout,
                'audio_tags' => $audioTags,
                'format_duration' => $formatDuration,
                'format_size' => $formatSize,
                'format_bit_rate' => $formatBitRate,
                'format_nb_streams' => $formatNbStreams,
                'format_tags' => $formatTags,
                'processStartTime' => $processStartTime,
                'isBadSource' => $isBadSource,
            ];
        }
        Log::info('Processed statsData (including episodes): ', $stats);
        return $stats;
    }
}
