<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Services\PlayerTranscodingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Transcode Controller - FFmpeg transcoding endpoint for web players
 *
 * This controller provides FFmpeg-based transcoding for channels and episodes
 * to ensure optimal playback in web streaming players (video-preview, series-preview).
 *
 * Features:
 * - Fast connection via minimal processing
 * - Stereo audio conversion for web compatibility
 * - Converts HLS/M3U8 sources to MPEG-TS for consistent web playback
 * - Support for MP4, MKV input formats
 * - Efficient Symphony Process handling with quick teardown on disconnect
 */
class AppPlayerController extends Controller
{
    /**
     * Stream transcoded content using FFmpeg
     *
     * @param  string  $streamUrl  Source stream URL
     * @param  string  $format  Output format
     * @param  string  $title  Stream title for logging
     * @param  Playlist|null  $playlist  Playlist context for user agent
     */
    public function streamTranscodedContent(
        Request $request,
        string $streamUrl,
        string $format,
        string $title,
        ?Playlist $playlist
    ): StreamedResponse {
        // Set appropriate headers based on format
        $headers = $this->getHeadersForFormat($format);

        // Create streaming response
        return new StreamedResponse(
            function () use ($streamUrl, $format, $title, $playlist) {
                // Disable time limits and buffering for streaming
                @ini_set('max_execution_time', 0);
                @ini_set('output_buffering', 'off');
                @ini_set('implicit_flush', 1);
                @apache_setenv('no-gzip', '1');

                if (ob_get_level()) {
                    ob_end_clean();
                }

                // Create transcoding service
                $transcodingService = new PlayerTranscodingService;

                // Build options
                $options = [];
                if ($playlist && $playlist->user_agent) {
                    $options['user_agent'] = $playlist->user_agent;
                }

                // Detect if client disconnected
                $clientConnected = true;

                try {
                    // Start transcoding with output callback
                    $transcodingService->startTranscode(
                        $streamUrl,
                        $format === 'm3u8' ? 'hls' : $format,
                        function ($data) use (&$clientConnected) {
                            if (connection_aborted()) {
                                $clientConnected = false;

                                return;
                            }

                            echo $data;
                            flush();
                        },
                        $options
                    );

                    Log::channel('ffmpeg')->info('FFmpeg transcoding started', [
                        'title' => $title,
                        'format' => $format,
                    ]);

                    // Wait for process while client is connected
                    while ($transcodingService->isRunning() && $clientConnected) {
                        usleep(100000); // 100ms

                        if (connection_aborted()) {
                            $clientConnected = false;
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    Log::channel('ffmpeg')->error('Transcoding error', [
                        'title' => $title,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                } finally {
                    // Always stop the transcoding process
                    $transcodingService->stop();

                    $exitCode = $transcodingService->getExitCode();
                    $reason = $clientConnected ? 'process ended' : 'client disconnected';

                    Log::channel('ffmpeg')->info('FFmpeg transcoding stopped', [
                        'title' => $title,
                        'reason' => $reason,
                        'exit_code' => $exitCode,
                    ]);

                    if ($exitCode !== 0 && $exitCode !== null) {
                        Log::channel('ffmpeg')->error('FFmpeg error output', [
                            'title' => $title,
                            'error_output' => $transcodingService->getErrorOutput(),
                        ]);
                    }
                }
            },
            200,
            $headers
        );
    }

    /**
     * Get HTTP headers for the given format
     */
    private function getHeadersForFormat(string $format): array
    {
        $commonHeaders = [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Connection' => 'keep-alive',
            'Access-Control-Allow-Origin' => '*',
            'X-Accel-Buffering' => 'no', // Disable nginx buffering
        ];

        $contentType = match ($format) {
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'hls' => 'video/mp2t', // Serve HLS as MPEG-TS for compatibility
            'm3u8' => 'video/mp2t', // Serve HLS as MPEG-TS for compatibility
            'ts' => 'video/mp2t',
            default => 'application/octet-stream',
        };

        return array_merge($commonHeaders, [
            'Content-Type' => $contentType,
        ]);
    }
}
