<?php

namespace App\Services;

use App\Models\Playlist;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

/**
 * Transcoding Service for converting streams to web-compatible formats
 *
 * This service uses FFmpeg to transcode streams with minimal processing for
 * optimal performance in web streaming players (video-preview, series-preview).
 * Features:
 * - Fast connection via stream copy when possible
 * - Stereo audio conversion for web compatibility
 * - Converts HLS/M3U8 inputs to MPEG-TS for consistent web playback
 * - Support for MPEG-TS, MP4, MKV, AVI, and more input formats
 * - VAAPI hardware acceleration when available (Docker with /dev/dri mapped)
 * - Efficient Symfony Process handling with quick teardown
 */
class PlayerTranscodingService
{
    private ?Process $process = null;

    /**
     * Stream transcoded content using FFmpeg
     *
     * @param  string  $streamUrl  Source stream URL
     * @param  string  $format  Output format
     * @param  string  $title  Stream title for logging
     * @param  Playlist|null  $playlist  Playlist context for user agent
     */
    public static function streamTranscodedContent(
        Request $request,
        string $streamUrl,
        string $format,
        string $title,
        ?Playlist $playlist
    ): StreamedResponse {
        // Set appropriate headers based on format
        $headers = self::getHeadersForFormat($format);

        // Create streaming response
        return new StreamedResponse(
            function () use ($streamUrl, $format, $title, $playlist) {
                // Disable time limits and buffering for streaming
                @ini_set('max_execution_time', 0);
                @ini_set('output_buffering', 'off');
                @ini_set('implicit_flush', 1);

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
    private static function getHeadersForFormat(string $format): array
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

    /**
     * Start transcoding a stream URL
     *
     * @param  string  $sourceUrl  The source stream URL
     * @param  string  $outputFormat  Output format (ts, hls, mp4)
     * @param  callable  $outputCallback  Callback to handle output data
     * @param  array  $options  Additional FFmpeg options
     */
    public function startTranscode(
        string $sourceUrl,
        string $outputFormat = 'ts',
        ?callable $outputCallback = null,
        array $options = []
    ): Process {
        $ffmpegPath = $this->getFfmpegPath();

        // Build FFmpeg command with minimal processing
        $command = $this->buildFfmpegCommand(
            $ffmpegPath,
            $sourceUrl,
            $outputFormat,
            $options
        );

        Log::channel('ffmpeg')->info('Starting FFmpeg transcoding', [
            'source_url' => $sourceUrl,
            'output_format' => $outputFormat,
            'command' => implode(' ', $command),
        ]);

        // Create Symphony Process
        $this->process = new Process($command);
        $this->process->setTimeout(null);
        $this->process->setIdleTimeout(null);

        // Start the process asynchronously with improved error filtering
        $this->process->start(function ($type, $buffer) use ($outputCallback) {
            if ($outputCallback && $type === Process::OUT) {
                $outputCallback($buffer);
            }

            // Filter out noise from stderr logs
            if ($type === Process::ERR) {
                // Skip repeated messages and common H.264 warnings
                if (
                    strpos($buffer, 'Last message repeated') !== false ||
                    strpos($buffer, 'mmco: unref short failure') !== false
                ) {
                    // Don't log these common repeated messages
                    return;
                }

                // Only log significant errors
                Log::channel('ffmpeg')->debug('FFmpeg stderr', ['output' => trim($buffer)]);
            }
        });

        return $this->process;
    }

    /**
     * Build FFmpeg command for transcoding
     */
    private function buildFfmpegCommand(
        string $ffmpegPath,
        string $sourceUrl,
        string $outputFormat,
        array $options
    ): array {
        $command = [
            $ffmpegPath,
            '-hide_banner',
            '-loglevel',
            'warning',
        ];

        // Add user agent if provided
        if (! empty($options['user_agent'])) {
            $command[] = '-user_agent';
            $command[] = $options['user_agent'];
        }

        // Add headers if provided
        if (! empty($options['headers'])) {
            foreach ($options['headers'] as $header) {
                $command[] = '-headers';
                $command[] = $header;
            }
        }

        // Input timeout and reconnect options with low-latency optimizations and error suppression
        $command = array_merge($command, [
            '-timeout',
            '10000000', // 10 seconds in microseconds
            '-reconnect',
            '1',
            '-reconnect_streamed',
            '1',
            '-reconnect_delay_max',
            '5',
            '-fflags',
            '+genpts+flush_packets', // Generate PTS and flush packets immediately
            '-flags',
            'low_delay', // Enable low delay mode
            '-err_detect',
            'ignore_err', // Ignore decoding errors (reduces H.264 mmco warnings)
            '-i',
            $sourceUrl,
        ]);

        // Check for hardware acceleration availability
        $useVAAPI = self::isVAAPIAvailable();
        
        // Video codec - optimized for real-time streaming with hardware acceleration when available
        if ($useVAAPI) {
            Log::channel('ffmpeg')->info('Using VAAPI hardware acceleration for transcoding');
            $command = array_merge($command, [
                '-hwaccel',
                'vaapi',
                '-hwaccel_device',
                '/dev/dri/renderD128',
                '-hwaccel_output_format',
                'vaapi',
                '-vf',
                'format=nv12|vaapi,hwupload', // Upload to VAAPI surface and ensure compatible format
                '-c:v',
                'h264_vaapi',
                '-profile:v',
                'main',
                '-level:v',
                '4.0',
                '-b:v',
                '2M', // 2Mbps bitrate for good quality/performance balance
                '-maxrate:v',
                '2.5M',
                '-bufsize:v',
                '4M',
                '-g',
                '25', // Keyframe every 25 frames (1 second at 25fps)
                '-keyint_min',
                '25', // Minimum keyframe interval
                '-bf',
                '0', // No B-frames for lowest latency
                '-refs',
                '1', // Only 1 reference frame for speed
                '-compression_level',
                '1', // Fast compression for low latency
            ]);
        } else {
            // Fallback to software encoding
            $command = array_merge($command, [
                '-c:v',
                'libx264',
                '-preset',
                'ultrafast',
                '-tune',
                'zerolatency',
                '-crf',
                '23',
                '-g',
                '25', // Keyframe every 25 frames (1 second at 25fps)
                '-keyint_min',
                '25', // Minimum keyframe interval
                '-sc_threshold',
                '0', // Disable scene change detection (can cause delays)
                '-bf',
                '0', // No B-frames for lowest latency
                '-refs',
                '1', // Only 1 reference frame for speed
                '-threads',
                '0', // Use all available CPU cores
            ]);
        }

        // Audio codec - convert to stereo AAC for web compatibility
        if (! empty($options['preserve_audio'])) {
            $command[] = '-c:a';
            $command[] = 'copy';
        } else {
            $command = array_merge($command, [
                '-c:a',
                'aac',
                '-b:a',
                '128k',
                '-ac',
                '2', // Force stereo for web player compatibility
                '-ar',
                '48000', // 48kHz sample rate
                '-aac_coder',
                'fast', // Use fast AAC encoder for lower latency
            ]);
        }

        // Format-specific options
        switch ($outputFormat) {
            case 'mp4':
                $command = array_merge($command, [
                    '-f',
                    'mp4',
                    '-movflags',
                    'frag_keyframe+empty_moov+faststart',
                ]);
                break;

            case 'mkv':
                $command = array_merge($command, [
                    '-f',
                    'matroska',
                ]);
                break;

            case 'hls':
            case 'm3u8':
            case 'ts':
            default:
                // Optimized MPEG-TS output for real-time streaming with minimal buffering
                $command = array_merge($command, [
                    '-f',
                    'mpegts',
                    '-mpegts_flags',
                    'resend_headers',
                    '-muxrate',
                    '0', // Variable bitrate (no rate limiting)
                    '-flush_packets',
                    '1', // Flush packets immediately
                ]);
                break;
        }

        // Add output pipe
        $command[] = 'pipe:1';

        return $command;
    }

    /**
     * Get FFmpeg executable path
     */
    private function getFfmpegPath(): string
    {
        $settings = app(GeneralSettings::class);
        $ffmpegPath = $settings->ffmpeg_path ?? 'jellyfin-ffmpeg'; // Default to 'jellyfin-ffmpeg' if not in settings for some reason
        return $ffmpegPath;
    }

    /**
     * Stop the transcoding process cleanly
     */
    public function stop(): void
    {
        if ($this->process && $this->process->isRunning()) {
            Log::channel('ffmpeg')
                ->info('Stopping FFmpeg transcoding process');

            // Send SIGTERM for graceful shutdown, but don't wait long
            $this->process->stop(1, SIGTERM);

            // If still running after 1 second, force kill immediately
            if ($this->process->isRunning()) {
                $this->process->stop(0, SIGKILL);
            }

            // Give it a moment to clean up and suppress any final error output
            usleep(100000); // 100ms
        }

        $this->process = null;
    }

    /**
     * Check if process is running
     */
    public function isRunning(): bool
    {
        return $this->process && $this->process->isRunning();
    }

    /**
     * Get process exit code
     */
    public function getExitCode(): ?int
    {
        return $this->process?->getExitCode();
    }

    /**
     * Get process error output
     */
    public function getErrorOutput(): string
    {
        return $this->process?->getErrorOutput() ?? '';
    }

    /**
     * Check if VAAPI hardware acceleration is available
     * 
     * @return bool
     */
    private static function isVAAPIAvailable(): bool
    {
        // Check if we're likely in a Docker environment with DRI device mapped
        if (!file_exists('/dev/dri/renderD128')) {
            return false;
        }

        // Check if the DRI device is readable/writable
        if (!is_readable('/dev/dri/renderD128') || !is_writable('/dev/dri/renderD128')) {
            return false;
        }

        // Check if FFmpeg supports VAAPI (check for h264_vaapi encoder)
        try {
            $ffmpegPath = app(GeneralSettings::class)->ffmpeg_path ?? 'jellyfin-ffmpeg';
            $process = new Process([$ffmpegPath, '-encoders']);
            $process->setTimeout(10);
            $process->run();

            if ($process->isSuccessful()) {
                $output = $process->getOutput();
                if (strpos($output, 'h264_vaapi') !== false) {
                    Log::channel('ffmpeg')->info('VAAPI hardware acceleration is available');
                    return true;
                }
            }
        } catch (\Exception $e) {
            Log::channel('ffmpeg')->debug('Failed to check VAAPI availability', [
                'error' => $e->getMessage()
            ]);
        }

        Log::channel('ffmpeg')->info('VAAPI hardware acceleration is not available, using software encoding');
        return false;
    }

    /**
     * Destructor to ensure cleanup
     */
    public function __destruct()
    {
        $this->stop();
    }
}
