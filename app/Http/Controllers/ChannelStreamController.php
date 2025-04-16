<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Channel;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        // Setup streams array
        $streamUrls = [
            $channel->url_custom ?? $channel->url
            // leave this here for future use...
            // @TODO: implement ability to assign fallback channels
        ];

        // Get user preferences
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'ffmpeg_debug' => false,
            'ffmpeg_max_tries' => 3,
            'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
        ];
        try {
            $settings = [
                'ffmpeg_debug' => $userPreferences->ffmpeg_debug ?? $settings['ffmpeg_debug'],
                'ffmpeg_max_tries' => $userPreferences->ffmpeg_max_tries ?? $settings['ffmpeg_max_tries'],
                'ffmpeg_user_agent' => $userPreferences->ffmpeg_user_agent ?? $settings['ffmpeg_user_agent'],
            ];
        } catch (Exception $e) {
            // Ignore
        }
        return new StreamedResponse(function () use ($streamUrls, $title, $settings) {
            while (ob_get_level()) {
                ob_end_flush();
            }
            flush();

            // Disable output buffering to ensure real-time streaming
            ini_set('zlib.output_compression', 0);

            // Get user agent
            $userAgent = $settings['ffmpeg_user_agent'];

            // Loop through available streams...
            foreach ($streamUrls as $streamUrl) {
                // Setup FFmpeg command
                $cmd = "ffmpeg -i \"$streamUrl\" -c copy -f mpegts -mpegts_flags +resend_headers+latm pipe:1 "
                    . "-user_agent \"$userAgent\" -referer \"MyComputer\" -noautorotate "
                    . "-fflags +nobuffer+discardcorruptts -flags low_delay -probesize 32 -analyzeduration 0 "
                    // HW acceleration only works with Intel QuickSync (requires `/dev/dri` be mapped to the container)
                    // Not using for now until we know if it's needed...
                    // . "-init_hw_device qsv=dev1:hw_any,child_device=/dev/dri/renderD128 -filter_hw_device dev1 "
                    . "-multiple_requests 1 -reconnect_on_network_error 1 -reconnect_on_http_error 5xx,4xx "
                    . "-reconnect_streamed 1 -reconnect_delay_max 8 -use_wallclock_as_timestamps 1";

                if (!$settings['ffmpeg_debug']) {
                    $cmd .= " -hide_banner -nostats -loglevel error";
                }

                // Continue trying until the client disconnects, or max retries are reached
                $maxRetries = $settings['ffmpeg_max_tries'];
                $retries = 0;
                while (!connection_aborted()) {
                    $process = SymphonyProcess::fromShellCommandline($cmd);
                    $process->setTimeout(0);
                    $process->setIdleTimeout(0);
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
                            if ($type === SymphonyProcess::ERR) {
                                Log::channel('ffmpeg')->error($buffer);
                            }
                        });
                    } catch (\Exception $e) {
                        // Log eror and attempt to reconnect.
                        if (!connection_aborted()) {
                            Log::channel('ffmpeg')
                                ->error("Error streaming channel (\"$title\"): " . $e->getMessage());
                        }
                    }
                    // If we get here, the process ended.
                    if (connection_aborted()) {
                        return;
                    }
                    if (++$retries >= $maxRetries) {
                        // Log error and stop trying this stream...
                        Log::channel('ffmpeg')
                            ->error("FFmpeg error: max retries of $maxRetries reached for stream for channel $title.");

                        // ...break and try the next stream
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
            'Content-Disposition' => "inline; filename=\"stream.ts\"",
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
