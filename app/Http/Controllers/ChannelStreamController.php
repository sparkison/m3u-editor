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

        $streamUrls = [
            $channel->url_custom ?? $channel->url,
            // Fallback to the custom URL if available (not yet implemented)
            // ...
        ];

        return new StreamedResponse(function () use ($streamUrls) {
            if (ob_get_level() > 0) {
                flush();
            }

            // Disable output buffering to ensure real-time streaming
            ini_set('zlib.output_compression', 0);

            // Loop through available streams...
            foreach ($streamUrls as $streamUrl) {
                // Setup FFmpeg command
                $cmd = "ffmpeg -re -i \"$streamUrl\" -c copy -f mpegts pipe:1 -reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5";
                $ffmpegLogPath = storage_path('logs/' . config('dev.ffmpeg.file'));
                if (config('dev.ffmpeg.debug')) {
                    // Log everything
                    $cmd .= " 2> " . $ffmpegLogPath;
                } else {
                    // Log only errors and hide stats
                    $cmd .= " -hide_banner -nostats -loglevel error 2> " . $ffmpegLogPath;
                }

                // Continue trying until the client disconnects
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
