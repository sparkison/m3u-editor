<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process as SymphonyProcess;

class ChannelStreamController extends Controller
{
    /**
     * Stream an IPTV channel.
     *
     * @param Request $request
     * @param int $id
     *
     * @return StreamedResponse
     */
    public function __invoke(Request $request, $id)
    {
        // Prevent timeouts, etc.
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        // Find the channel by ID
        if (strpos($id, '==') === false) {
            $id .= '=='; // right pad to ensure proper decoding
        }
        $channel = Channel::findOrFail(base64_decode($id));

        $title = $channel->title_custom ?? $channel->title;
        $title = strip_tags($title);

        $streamUrls = [
            $channel->url_custom ?? $channel->url,
            // Fallback to the custom URL if available (not yet implemented)
            // ...
        ];

        return new StreamedResponse(function () use ($streamUrls, $title) {
            if (ob_get_level() > 0) {
                flush();
            }

            // Disable output buffering to ensure real-time streaming
            ini_set('zlib.output_compression', 0);

            // Loop through available streams...
            foreach ($streamUrls as $streamUrl) {
                // Setup FFmpeg command
                $userAgent = config('dev.ffmpeg.user_agent');
                $ffmpegLogPath = storage_path('logs/' . config('dev.ffmpeg.file'));
                $cmd = "ffmpeg -re -i \"$streamUrl\" -c copy -f mpegts pipe:1 -user_agent \"$userAgent\" -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5";
                if (config('dev.ffmpeg.debug')) {
                    // Log everything
                    $cmd .= " 2> " . $ffmpegLogPath;
                } else {
                    // Log only errors and hide stats
                    $cmd .= " -hide_banner -nostats -loglevel error 2> " . $ffmpegLogPath;
                }

                // Continue trying until the client disconnects, or max retries are reached
                $maxRetries = config('dev.ffmpeg.max_retries');
                $retries = 0;
                while (!connection_aborted()) {
                    $process = SymphonyProcess::fromShellCommandline($cmd);
                    $process->setTimeout(null); // Make sure not to timeout prematurely
                    try {
                        $process->run(function ($type, $buffer) {
                            if (connection_aborted()) {
                                throw new \Exception("Connection aborted by client.");
                            }
                            if ($type === SymphonyProcess::OUT) {
                                echo $buffer;
                                flush();
                                usleep(10000); // Reduce CPU usage
                            }
                        });
                    } catch (\Exception $e) {
                        // Log eror and attempt to reconnect.
                        if (!connection_aborted()) {
                            error_log("FFmpeg error: " . $e->getMessage());
                        }
                    }
                    // If we get here, the process ended.
                    if (connection_aborted()) {
                        return;
                    }
                    if (++$retries >= $maxRetries) {
                        error_log("FFmpeg error: max retries of $maxRetries reached for stream for channel $title.");
                        // Stop the process and break out of the loop
                        $process->stop();
                        break;
                    }
                    // Wait a short period before trying to reconnect.
                    sleep(1);
                }
            }

            echo "Error: No available streams.";
        }, 200, [
            'Content-Type' => 'video/mp2t',
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-store, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
