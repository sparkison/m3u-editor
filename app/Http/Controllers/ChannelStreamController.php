<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Process;

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
                if (config('dev.ffmpeg.debug')) {
                    $cmd .= " 2> " . storage_path('logs/' . config('dev.ffmpeg.file'));
                } else {
                    $cmd .= " -hide_banner -nostats -loglevel quiet 2>/dev/null";
                }

                // Start FFmpeg process with Laravel's Process facade
                $process = Process::start($cmd);
                try {
                    while ($process->running()) {
                        if (connection_aborted()) {
                            $process->signal(SIGKILL); // Kill FFmpeg process
                            return;
                        }

                        echo $process->latestOutput();
                        flush();
                    }
                    return;
                } catch (Exception $e) {
                    $process->signal(SIGKILL); // Ensure process is terminated
                    error_log("FFmpeg error: " . $e->getMessage());
                    continue; // Try next stream URL
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
