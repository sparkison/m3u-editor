<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Settings\GeneralSettings;
use App\Services\DirectStreamManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ChannelStreamController extends Controller
{
    protected $streamManager;

    public function __construct(DirectStreamManager $streamManager)
    {
        $this->streamManager = $streamManager;
    }

    /**
     * Stream an IPTV channel.
     *
     * @param Request $request
     * @param int|string $encodedId
     * @param string|null $encodedPlaylist
     * @param string $format
     *
     * @return \Illuminate\Http\Response
     */
    public function __invoke(
        Request $request,
        $encodedId,
        $format = 'mp2t',
        $encodedPlaylist = null
    ) {
        // Validate the format
        if (!in_array($format, ['mp2t', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        // Prevent timeouts, etc.
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($encodedId));
        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);
        $channelId = $channel->id;

        // Check if playlist is specified
        $playlist = null;
        if ($encodedPlaylist) {
            if (strpos($encodedPlaylist, '==') === false) {
                $encodedPlaylist .= '=='; // right pad to ensure proper decoding
            }
            $playlistId = base64_decode($encodedPlaylist);
            $playlist = Playlist::where('uuid', $playlistId)->first();
            if (!$playlist) {
                $playlist = MergedPlaylist::where('uuid', $playlistId)->first();
            }
            if (!$playlist) {
                $playlist = CustomPlaylist::where('uuid', $playlistId)->firstOrFail();
            }
        }

        // Setup stream URL
        $streamUrl = $channel->url_custom ?? $channel->url;

        // Get user preferences/settings
        $settings = $this->getStreamSettings($playlist);

        // Get user agent
        $userAgent = $settings['ffmpeg_user_agent'];
        if ($playlist) {
            $userAgent = $playlist->user_agent ?? $userAgent;
        }

        // Generate a unique viewer ID for tracking
        $ip = $request->ip();
        $viewerId = "{$ip}:" . Str::random(8);

        // Set up extension and content type
        $extension = $format === 'mp2t' ? 'ts' : $format;

        try {
            // Get or create the stream
            $pipePath = $this->streamManager->getOrCreateStream(
                $channelId,
                $format,
                $settings,
                $streamUrl,
                $userAgent
            );

            // Add this viewer to the tracking
            $this->streamManager->addViewer($channelId, $format, $ip);

            // Register a shutdown function to remove the viewer when the connection ends
            register_shutdown_function(function () use ($channelId, $format, $viewerId) {
                $this->streamManager->removeViewer($channelId, $format, $viewerId);
            });

            // Determine the internal path for NGINX
            $nginxPath = "/internal/stream/channel_{$channelId}.{$extension}";

            // Return the response via NGINX internal redirect
            return response('', 200, [
                'X-Accel-Redirect' => $nginxPath,
                'Content-Type' => "video/{$format}",
                'Connection' => 'keep-alive',
                'Cache-Control' => 'no-store, no-transform',
                'Content-Disposition' => "inline; filename=\"stream.{$extension}\"",
                'X-Accel-Buffering' => 'no',
            ]);
        } catch (\Exception $e) {
            Log::error("Stream error for channel {$channelId}: " . $e->getMessage());
            return response("Error: " . $e->getMessage(), 500);
        }
    }

    /**
     * Get all settings needed for streaming
     */
    protected function getStreamSettings($playlist = null): array
    {
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'ffmpeg_debug' => false,
            'ffmpeg_max_tries' => 3,
            'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
            'ffmpeg_codec_video' => 'copy',
            'ffmpeg_codec_audio' => 'copy',
            'ffmpeg_codec_subtitles' => 'copy',
            'ffmpeg_path' => 'jellyfin-ffmpeg',
        ];

        try {
            $settings = [
                'ffmpeg_debug' => $userPreferences->ffmpeg_debug ?? $settings['ffmpeg_debug'],
                'ffmpeg_max_tries' => $userPreferences->ffmpeg_max_tries ?? $settings['ffmpeg_max_tries'],
                'ffmpeg_user_agent' => $userPreferences->ffmpeg_user_agent ?? $settings['ffmpeg_user_agent'],
                'ffmpeg_codec_video' => $userPreferences->ffmpeg_codec_video ?? $settings['ffmpeg_codec_video'],
                'ffmpeg_codec_audio' => $userPreferences->ffmpeg_codec_audio ?? $settings['ffmpeg_codec_audio'],
                'ffmpeg_codec_subtitles' => $userPreferences->ffmpeg_codec_subtitles ?? $settings['ffmpeg_codec_subtitles'],
                'ffmpeg_path' => $userPreferences->ffmpeg_path ?? $settings['ffmpeg_path'],
            ];

            // Add any additional args from config
            $settings['ffmpeg_additional_args'] = config('proxy.ffmpeg_additional_args', '');
        } catch (Exception $e) {
            // Ignore
        }

        return $settings;
    }
}
