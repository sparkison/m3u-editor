<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Process;

class StreamTestController extends Controller
{
    /**
     * Generate a test stream with a timer overlay.
     * 
     * @param Request $request
     * @param int $timeout - 0 for no limit, or seconds to count down
     * @return StreamedResponse
     */
    public function testStream(Request $request, int $timeout = 0): StreamedResponse
    {
        // Validate timeout
        if ($timeout < 0) {
            abort(400, 'Timeout must be 0 or positive integer');
        }

        // Set appropriate headers for TS streaming
        $headers = [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Connection' => 'keep-alive',
            'Access-Control-Allow-Origin' => '*',
        ];

        return new StreamedResponse(function () use ($timeout) {
            $this->generateTestStream($timeout);
        }, 200, $headers);
    }

    /**
     * Generate the actual test stream content
     * 
     * @param int $timeout
     * @return void
     */
    private function generateTestStream(int $timeout): void
    {
        $startTime = time();
        
        Log::info("Starting test stream", [
            'timeout' => $timeout,
            'start_time' => $startTime
        ]);

        // Check if FFmpeg is available for proper video generation
        if ($this->isFFmpegAvailable()) {
            $this->generateFFmpegStream($timeout, $startTime);
        } else {
            $this->generateBasicStream($timeout, $startTime);
        }
    }

    /**
     * Check if FFmpeg is available on the system
     * 
     * @return bool
     */
    private function isFFmpegAvailable(): bool
    {
        try {
            $process = new Process(['ffmpeg', '-version']);
            $process->run();
            return $process->isSuccessful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate a proper video stream using FFmpeg
     * 
     * @param int $timeout
     * @param int $startTime
     * @return void
     */
    private function generateFFmpegStream(int $timeout, int $startTime): void
    {
        try {
            // Create FFmpeg command for test pattern with timer
            $cmd = [
                'ffmpeg',
                '-f', 'lavfi',
                '-i', 'testsrc2=size=1280x720:rate=25', // 720p test pattern
                '-f', 'lavfi',
                '-i', 'sine=frequency=1000:sample_rate=48000', // 1kHz tone
                '-filter_complex', $this->buildFFmpegFilter($timeout, $startTime),
                '-c:v', 'libx264',
                '-preset', 'ultrafast',
                '-tune', 'zerolatency',
                '-c:a', 'aac',
                '-b:a', '128k',
                '-f', 'mpegts',
                '-'
            ];

            $process = new Process($cmd);
            $process->setTimeout($timeout > 0 ? $timeout + 5 : null);
            
            $process->start();
            
            $lastOutput = time();
            
            foreach ($process as $type => $data) {
                if ($type === Process::OUT) {
                    echo $data;
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                    $lastOutput = time();
                }
                
                // Check timeout
                if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                    $process->stop();
                    break;
                }
                
                // Check if connection is still alive
                if (connection_aborted()) {
                    $process->stop();
                    Log::info("Test stream connection aborted (FFmpeg)", ['elapsed' => time() - $startTime]);
                    break;
                }
                
                // Check if process is stalled
                if ((time() - $lastOutput) > 10) {
                    Log::warning("FFmpeg process seems stalled, stopping");
                    $process->stop();
                    break;
                }
            }
            
        } catch (\Exception $e) {
            Log::error("FFmpeg stream error", [
                'error' => $e->getMessage(),
                'elapsed' => time() - $startTime
            ]);
            
            // Fallback to basic stream
            $this->generateBasicStream($timeout, $startTime);
        }
    }

    /**
     * Build FFmpeg filter for timer overlay
     * 
     * @param int $timeout
     * @param int $startTime
     * @return string
     */
    private function buildFFmpegFilter(int $timeout, int $startTime): string
    {
        if ($timeout > 0) {
            // Countdown timer
            return sprintf(
                "[0:v]drawtext=text='Test Stream - Countdown\\: %%{eif\\:max(0\\,%d-t)\\:d}s':fontsize=48:fontcolor=white:x=(w-tw)/2:y=(h-th)/2:box=1:boxcolor=black@0.5[v]",
                $timeout
            );
        } else {
            // Runtime timer
            return "[0:v]drawtext=text='Test Stream - Runtime\\: %{pts\\:gmtime\\:0\\:%H\\\\\\:%M\\\\\\:%S}':fontsize=48:fontcolor=white:x=(w-tw)/2:y=(h-th)/2:box=1:boxcolor=black@0.5[v]";
        }
    }

    /**
     * Generate a basic fallback stream
     * 
     * @param int $timeout
     * @param int $startTime
     * @return void
     */
    private function generateBasicStream(int $timeout, int $startTime): void
    {
        $counter = 0;
        $segmentDuration = 2; // 2 seconds per segment
        
        try {
            while (true) {
                $currentTime = time();
                $elapsed = $currentTime - $startTime;
                
                // Check if we should stop (timeout reached)
                if ($timeout > 0 && $elapsed >= $timeout) {
                    Log::info("Test stream timeout reached", ['elapsed' => $elapsed, 'timeout' => $timeout]);
                    break;
                }
                
                // Calculate display time
                if ($timeout > 0) {
                    $displayTime = max(0, $timeout - $elapsed);
                    $timeText = sprintf("Countdown: %02d:%02d", floor($displayTime / 60), $displayTime % 60);
                } else {
                    $timeText = sprintf("Runtime: %02d:%02d", floor($elapsed / 60), $elapsed % 60);
                }
                
                // Generate a simple TS segment
                $tsSegment = $this->generateTsSegment($timeText, $counter, $segmentDuration);
                
                // Output the segment
                echo $tsSegment;
                
                // Flush the output buffer
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                
                // Check if connection is still alive
                if (connection_aborted()) {
                    Log::info("Test stream connection aborted", ['elapsed' => $elapsed]);
                    break;
                }
                
                $counter++;
                
                // Sleep for segment duration
                sleep($segmentDuration);
            }
        } catch (\Exception $e) {
            Log::error("Test stream error", [
                'error' => $e->getMessage(),
                'elapsed' => time() - $startTime
            ]);
        }
        
        Log::info("Test stream ended", [
            'duration' => time() - $startTime,
            'segments_sent' => $counter
        ]);
    }

    /**
     * Generate a minimal TS segment with embedded timer
     * 
     * @param string $timeText
     * @param int $counter
     * @param int $duration
     * @return string
     */
    private function generateTsSegment(string $timeText, int $counter, int $duration): string
    {
        // This is a simplified TS packet structure
        // In a real implementation, you would use FFmpeg or similar
        // For testing purposes, we'll create a minimal valid TS structure
        
        $segmentData = $this->createMinimalTsPacket($timeText, $counter, $duration);
        
        return $segmentData;
    }

    /**
     * Create a minimal TS packet structure
     * This is a very basic implementation for testing purposes
     * 
     * @param string $timeText
     * @param int $counter
     * @param int $duration
     * @return string
     */
    private function createMinimalTsPacket(string $timeText, int $counter, int $duration): string
    {
        // TS packet is 188 bytes
        $packetSize = 188;
        $packetsPerSegment = 500; // More packets for better streaming
        
        $segment = '';
        
        for ($i = 0; $i < $packetsPerSegment; $i++) {
            // Create a basic TS packet header
            $packet = '';
            
            // Sync byte (0x47)
            $packet .= chr(0x47);
            
            // Transport Error Indicator, Payload Unit Start Indicator, Transport Priority, PID (13 bits)
            $pid = ($i % 3 == 0) ? 0x0100 : (($i % 3 == 1) ? 0x0101 : 0x0102);
            $packet .= pack('n', $pid);
            
            // Scrambling control, Adaptation field control, Continuity counter
            $continuityCounter = ($counter + $i) % 16;
            $packet .= chr(0x10 | $continuityCounter);
            
            // Fill the rest with payload data (including our timer info)
            $payload = sprintf("Test Stream - %s - Segment #%d - Packet #%d - Time: %s", 
                $timeText, $counter, $i, date('Y-m-d H:i:s'));
            
            // Pad payload to fill packet
            $payload = str_pad($payload, $packetSize - 4, "\xFF");
            $packet .= substr($payload, 0, $packetSize - 4);
            
            $segment .= $packet;
        }
        
        return $segment;
    }

    /**
     * Generate an HLS playlist for the test stream
     * 
     * @param Request $request
     * @param int $timeout
     * @return \Illuminate\Http\Response
     */
    public function testPlaylist(Request $request, int $timeout = 0)
    {
        // Validate timeout
        if ($timeout < 0) {
            abort(400, 'Timeout must be 0 or positive integer');
        }

        $streamUrl = route('stream.test', ['timeout' => $timeout]);
        
        $playlist = "#EXTM3U\n";
        $playlist .= "#EXT-X-VERSION:3\n";
        $playlist .= "#EXT-X-TARGETDURATION:10\n";
        $playlist .= "#EXT-X-MEDIA-SEQUENCE:0\n";
        $playlist .= "#EXT-X-PLAYLIST-TYPE:EVENT\n";
        
        if ($timeout > 0) {
            $playlist .= "#EXT-X-ENDLIST\n";
        }
        
        $playlist .= "#EXTINF:10.0,\n";
        $playlist .= $streamUrl . "\n";
        
        return response($playlist)
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0')
            ->header('Access-Control-Allow-Origin', '*');
    }
}
