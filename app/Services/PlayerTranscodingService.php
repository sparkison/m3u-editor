<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Log;
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
 * - Efficient Symfony Process handling with quick teardown
 */
class PlayerTranscodingService
{
    private ?Process $process = null;

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

        // Start the process asynchronously
        $this->process->start(function ($type, $buffer) use ($outputCallback) {
            if ($outputCallback && $type === Process::OUT) {
                $outputCallback($buffer);
            }

            // Log errors
            if ($type === Process::ERR) {
                Log::channel('ffmpeg')->debug('FFmpeg stderr', ['output' => $buffer]);
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
            '-re', // Read input at native frame rate (important for streaming)
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

        // Input timeout and reconnect options
        $command = array_merge($command, [
            '-timeout',
            '10000000', // 10 seconds in microseconds
            '-reconnect',
            '1',
            '-reconnect_streamed',
            '1',
            '-reconnect_delay_max',
            '5',
            '-i',
            $sourceUrl,
        ]);

        // Video codec - copy if possible, otherwise use fast encoding
        if (! empty($options['force_transcode_video'])) {
            $command = array_merge($command, [
                '-c:v',
                'libx264',
                '-preset',
                'ultrafast',
                '-tune',
                'zerolatency',
                '-crf',
                '23',
            ]);
        } else {
            // Try to copy video stream for maximum efficiency
            $command[] = '-c:v';
            $command[] = 'copy';
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
                // Always output as MPEG-TS for web streaming compatibility
                // This handles HLS inputs by transcoding them to TS
                $command = array_merge($command, [
                    '-f',
                    'mpegts',
                    '-mpegts_flags',
                    'resend_headers',
                    '-mpegts_copyts',
                    '1',
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
     * Stop the transcoding process
     */
    public function stop(): void
    {
        if ($this->process && $this->process->isRunning()) {
            Log::channel('ffmpeg')
                ->info('Stopping FFmpeg transcoding process');

            // Send SIGTERM for graceful shutdown
            $this->process->stop(3, SIGTERM);

            // If still running after 3 seconds, force kill
            if ($this->process->isRunning()) {
                $this->process->stop(0, SIGKILL);
            }
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
     * Destructor to ensure cleanup
     */
    public function __destruct()
    {
        $this->stop();
    }
}
