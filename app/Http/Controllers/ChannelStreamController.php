<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Response;

class ChannelStreamController extends Controller
{
    /**
     * Stream an IPTV channel.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function __invoke(Request $request, $id)
    {
        // Find the channel by ID, else throw a 404
        $channel = Channel::findOrFail($id);

        // Get the stream URL
        $streamUrl = $channel->url;

        // Stream the content directly from FFmpeg
        return new StreamedResponse(function () use ($streamUrl) {
            $cmd = "ffmpeg -i \"$streamUrl\" -c copy -f mpegts pipe:1";
            $process = popen($cmd, 'r');

            while (!feof($process)) {
                echo fread($process, 4096);
                flush();
            }

            pclose($process);
        }, 200, [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
