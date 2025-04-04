<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        // Prevent timeouts
        ini_set('max_execution_time', 0);
        ini_set('output_buffering', 'off');
        ini_set('implicit_flush', 1);

        // Find the channel by ID, else throw a 404
        if (strpos($id, '==') === false) {
            $id .= '=='; // Ensure padding for base64 decoding
        }
        $channel = Channel::findOrFail(base64_decode($id));

        // Get the stream URLs (allow for multiple fallbacks)
        $streamUrls = [$channel->url_custom ?? $channel->url];

        return new StreamedResponse(function () use ($streamUrls) {
            if (ob_get_level() > 0) {
                flush();
            }
            ini_set('zlib.output_compression', 0);

            foreach ($streamUrls as $streamUrl) {
                $args = config('dev.ffmpeg.args');
                $cmd = "ffmpeg -re -i \"$streamUrl\" -c copy -f mpegts pipe:1 $args";
                if (config('dev.ffmpeg.debug')) {
                    $cmd .= " 2> " . storage_path('logs/' . config('dev.ffmpeg.file'));
                } else {
                    $cmd .= " -hide_banner -nostats -loglevel quiet 2>/dev/null";
                }

                try {
                    // Setup the stream process
                    $descriptorspec = [
                        0 => ['pipe', 'r'],  // STDIN
                        1 => ['pipe', 'w'],  // STDOUT
                        2 => ['pipe', 'w'],  // STDERR
                    ];
                    $process = proc_open($cmd, $descriptorspec, $pipes);

                    if (is_resource($process)) {
                        // Ensure process cleanup on request termination
                        register_shutdown_function(function () use ($process) {
                            if (is_resource($process)) {
                                proc_terminate($process);
                                proc_close($process);
                            }
                        });

                        stream_set_blocking($pipes[1], false); // Non-blocking mode

                        while (!feof($pipes[1])) {
                            echo fread($pipes[1], 8192);
                            flush();

                            if (function_exists('swoole_timer_after')) {
                                swoole_timer_after(1000, function () use ($process) {
                                    if (connection_aborted() && is_resource($process)) {
                                        proc_terminate($process);
                                        proc_close($process);
                                    }
                                });
                            }
                        }

                        proc_close($process);
                        return;
                    }
                } catch (Exception $e) {
                    proc_terminate($process);
                    proc_close($process);
                    error_log("FFmpeg error: " . $e->getMessage());

                    // Try next fallback URL if available
                    continue;
                }
            }
            echo "Error: No available streams.";
        }, 200, [
            'Content-Type' => 'video/mp2t',
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-store, no-transform',
            'X-Accel-Buffering' => 'no', // Prevents Nginx from buffering
        ]);
    }
}
