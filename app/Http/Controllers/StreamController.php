<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Models\Episode;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymphonyProcess;

class StreamController extends Controller
{
    /**
     * Stream a channel.
     *
     * @param Request $request
     * @param int|string $encodedId
     * @param string $format
     *
     * @return StreamedResponse
     */
    public function __invoke(
        Request $request,
        $encodedId,
        $format = 'ts',
    ) {
        // Validate the format
        if (!in_array($format, ['ts', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($encodedId));
        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);

        // Check if playlist is specified
        $playlist = $channel->playlist;

        // Setup streams array
        $streamUrl = $channel->url_custom ?? $channel->url;

        // Determine the output format
        $ip = $request->ip();
        $streamId = uniqid();
        $channelId = $channel->id;
        $contentType = $format === 'ts' ? 'video/MP2T' : 'video/mp4';

        // Start the stream
        return $this->startStream(
            type: 'channel',
            modelId: $channelId,
            streamUrl: $streamUrl,
            title: $title,
            format: $format,
            ip: $ip,
            streamId: $streamId,
            contentType: $contentType,
            userAgent: $playlist->user_agent ?? null
        );
    }

    /**
     * Stream an episode.
     *
     * @param Request $request
     * @param int|string $encodedId
     * @param string $format
     *
     * @return StreamedResponse
     */
    public function episode(
        Request $request,
        $encodedId,
        $format = 'ts',
    ) {
        // Validate the format
        if (!in_array($format, ['ts', 'mp4'])) {
            abort(400, 'Invalid format specified.');
        }

        // Find the channel by ID
        if (strpos($encodedId, '==') === false) {
            $encodedId .= '=='; // right pad to ensure proper decoding
        }
        $episode = Episode::findOrFail(base64_decode($encodedId));
        $title = $episode->title;
        $title = strip_tags($title);

        // Check if playlist is specified
        $playlist = $episode->playlist;

        // Setup streams array
        $streamUrl = $episode->url;

        // Determine the output format
        $ip = $request->ip();
        $streamId = uniqid();
        $episodeId = $episode->id;
        $contentType = $format === 'ts' ? 'video/MP2T' : 'video/mp4';

        // Start the stream
        return $this->startStream(
            type: 'episode',
            modelId: $episodeId,
            streamUrl: $streamUrl,
            title: $title,
            format: $format,
            ip: $ip,
            streamId: $streamId,
            contentType: $contentType,
            userAgent: $playlist->user_agent ?? null
        );
    }

    /**
     * Start the stream using FFmpeg.
     *
     * @param string $type
     * @param int $modelId
     * @param string $streamUrl
     * @param string $title
     * @param string $format
     * @param string $ip
     * @param string $streamId
     * @param string $contentType
     * @param string|null $userAgent
     *
     * @return StreamedResponse
     */
    private function startStream(
        $type,
        $modelId,
        $streamUrl,
        $title,
        $format,
        $ip,
        $streamId,
        $contentType,
        $userAgent
    ) {
        // Prevent timeouts, etc.
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        // Get user preferences
        $settings = $this->getStreamSettings();

        // Get user agent
        $userAgent = escapeshellarg($settings['ffmpeg_user_agent']);

        // Set the content type based on the format
        return new StreamedResponse(function () use ($modelId, $type, $streamUrl, $title, $settings, $format, $ip, $streamId, $userAgent) {
            // Set unique client key (order is used for stats output)
            $clientKey = "{$ip}::{$modelId}::{$streamId}::{$type}";

            // Make sure PHP doesn't ignore user aborts
            ignore_user_abort(false);

            // Register a shutdown function that ALWAYS runs when the script dies
            register_shutdown_function(function () use ($clientKey, $title) {
                Redis::srem('mpts:active_ids', $clientKey);
                Log::channel('ffmpeg')->info("Streaming stopped for channel {$title}");
            });

            // Mark as active
            Redis::sadd('mpts:active_ids', $clientKey);

            // Clear any existing output buffers
            // This is important for real-time streaming
            while (ob_get_level()) {
                ob_end_flush();
            }
            flush();

            // Disable output buffering to ensure real-time streaming
            ini_set('zlib.output_compression', 0);

            // Set the maximum number of retries
            $maxRetries = $settings['ffmpeg_max_tries'];

            // Get user defined options
            $userArgs = config('proxy.ffmpeg_additional_args', '');
            if (!empty($userArgs)) {
                $userArgs .= ' ';
            }

            // Get ffmpeg path
            $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
            if (empty($ffmpegPath)) {
                $ffmpegPath = 'jellyfin-ffmpeg';
            }

            // Get ffmpeg output codec formats
            $videoCodec = config('proxy.ffmpeg_codec_video') ?: $settings['ffmpeg_codec_video'];
            $audioCodec = config('proxy.ffmpeg_codec_audio') ?: $settings['ffmpeg_codec_audio'];
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles') ?: $settings['ffmpeg_codec_subtitles'];

            // Set the output format and codecs
            $output = $format === 'ts'
                ? "-c:v $videoCodec -c:a $audioCodec -c:s $subtitleCodec -f mpegts pipe:1"
                : "-ac 2 -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";

            // Determine if it's an MKV file by extension
            $isMkv = stripos($streamUrl, '.mkv') !== false;

            // Enhanced HTTP options for MKV files that often have connection issues
            $httpOptions = "-user_agent \"$userAgent\" -referer \"MyComputer\" " .
                '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                '-reconnect_delay_max 5';

            // Add extra options for MKV files
            if ($isMkv) {
                $httpOptions .= ' -analyzeduration 10M -probesize 10M';
            }
            $httpOptions .= ' -noautorotate';

            // Build the FFmpeg command
            $cmd = sprintf(
                $ffmpegPath . ' ' .
                    // Pre-input HTTP options:
                    '%s ' .

                    // User defined options:
                    '%s' .

                    // Input:
                    '-re -i "%s" ' .

                    // Progress tracking:
                    // '-progress pipe:2 ' . // Disabled for now

                    // Output:
                    '%s ' .

                    // Logging:
                    '%s',
                $httpOptions,                 // HTTP options
                $userArgs,                    // user defined options
                $streamUrl,                   // input URL
                $output,                      // for -f
                $settings['ffmpeg_debug'] ? '' : '-hide_banner -nostats -loglevel error'
            );

            // Log the command for debugging
            Log::channel('ffmpeg')->info("Streaming channel {$title} with command: {$cmd}");

            // Continue trying until the client disconnects, or max retries are reached
            $retries = 0;
            while (!connection_aborted()) {
                // Start the streaming process!
                $process = SymphonyProcess::fromShellCommandline($cmd);
                $process->setTimeout(null);
                try {
                    $streamType = $type;
                    $process->run(function ($type, $buffer) use ($modelId, $format, $streamType) {
                        if (connection_aborted()) {
                            throw new \Exception("Connection aborted by client.");
                        }
                        if ($type === SymphonyProcess::OUT) {
                            echo $buffer;
                            flush();
                            usleep(10000); // Reduce CPU usage
                        }
                        if ($type === SymphonyProcess::ERR) {
                            // split out each line
                            $lines = preg_split('/\r?\n/', trim($buffer));
                            foreach ($lines as $line) {
                                // Use below, along with `-progress pipe:2`, to enable stream progress tracking...
                                /*
                                        // "progress" lines are always KEY=VALUE
                                        if (strpos($line, '=') !== false) {
                                            list($key, $value) = explode('=', $line, 2);
                                            if (in_array($key, ['bitrate', 'fps', 'out_time_ms'])) {
                                                // push the metric value onto a Redis list and trim to last 20 points
                                                $listKey = "mpts:{$streamType}_hist:{$modelId}:{$key}";
                                                $timeKey = "mpts:{$streamType}_hist:{$modelId}:timestamps";

                                                // push the timestamp into a parallel list (once per loop)
                                                Redis::rpush($timeKey, now()->format('H:i:s'));
                                                Redis::ltrim($timeKey, -20, -1);

                                                // push the metric value
                                                Redis::rpush($listKey, $value);
                                                Redis::ltrim($listKey, -20, -1);
                                            }
                                        } elseif ($line !== '') {
                                            // anything else is a true ffmpeg log/error
                                            Log::channel('ffmpeg')->error($line);
                                        }
                                    */
                                Log::channel('ffmpeg')->error($line);
                            }
                        }
                    });
                } catch (\Exception $e) {
                    // Log eror and attempt to reconnect.
                    if (!connection_aborted()) {
                        Log::channel('ffmpeg')
                            ->error("Error streaming $type (\"$title\"): " . $e->getMessage());
                    }
                }

                // If we get here, the process ended.
                if (connection_aborted()) {
                    if ($process->isRunning()) {
                        $process->stop(1); // SIGTERM then SIGKILL
                    }
                    return;
                }
                if (++$retries >= $maxRetries) {
                    // Log error and stop trying this stream...
                    Log::channel('ffmpeg')
                        ->error("FFmpeg error: max retries of $maxRetries reached for stream for $type $title.");

                    // ...break and try the next stream
                    break;
                }
                // Wait a short period before trying to reconnect.
                sleep(min(8, $retries));
            }

            echo "Error: No available streams.";
        }, 200, [
            'Content-Type' => $contentType,
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-store, no-transform',
            'Content-Disposition' => "inline; filename=\"stream.$format\"",
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Get all settings needed for streaming
     */
    protected function getStreamSettings(): array
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
