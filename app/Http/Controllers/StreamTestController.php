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
            // Calculate initial display text
            if ($timeout > 0) {
                $timeText = sprintf("Countdown: %02d:%02d", floor($timeout / 60), $timeout % 60);
            } else {
                $timeText = "Runtime: 00:00";
            }
            
            // Prepare filter text (escape special characters for FFmpeg)
            $filterText = str_replace(['"', "'", ':', '\n'], ['\"', "\'", '\\:', '\\n'], $timeText . "\\nContinuous Stream");
            
            // Create FFmpeg command for continuous stream
            $cmd = [
                'ffmpeg',
                '-f', 'lavfi',
                '-i', 'testsrc2=size=1280x720:rate=25', // 720p test pattern at 25fps
                '-f', 'lavfi',
                '-i', 'sine=frequency=1000:sample_rate=48000', // 1kHz tone  
                '-filter_complex', "[0:v]drawtext=text='{$filterText}':fontsize=48:fontcolor=white:x=(w-tw)/2:y=(h-th)/2:box=1:boxcolor=black@0.5[v]",
                '-map', '[v]',
                '-map', '1:a'
            ];
            
            // Add timeout before codec options if specified
            if ($timeout > 0) {
                $cmd[] = '-t';
                $cmd[] = (string)$timeout;
            }
            
            // Add codec and output options
            $cmd = array_merge($cmd, [
                '-c:v', 'libx264',
                '-preset', 'ultrafast',
                '-tune', 'zerolatency',
                '-profile:v', 'baseline',
                '-level', '3.0',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'aac',
                '-b:a', '128k',
                '-f', 'mpegts',
                'pipe:1'
            ]);

            Log::info("Starting continuous FFmpeg stream", ['timeout' => $timeout, 'cmd' => implode(' ', $cmd)]);

            $process = new Process($cmd);
            $process->setTimeout($timeout > 0 ? $timeout + 5 : null);
            
            Log::info("Starting FFmpeg process", [
                'timeout' => $timeout,
                'process_timeout' => $timeout > 0 ? $timeout + 5 : null
            ]);
            
            $process->start();
            
            if (!$process->isStarted()) {
                Log::error("Failed to start FFmpeg process");
                throw new \Exception("Failed to start FFmpeg process");
            }
            
            $lastOutput = time();
            $totalBytes = 0;
            
            // Stream output as it becomes available
            while ($process->isRunning()) {
                // Read available output
                $output = $process->getIncrementalOutput();
                
                if (!empty($output)) {
                    echo $output;
                    $totalBytes += strlen($output);
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                    $lastOutput = time();
                }
                
                // Check if connection is still alive
                if (connection_aborted()) {
                    $process->stop();
                    Log::info("Test stream connection aborted (FFmpeg)", ['elapsed' => time() - $startTime]);
                    break;
                }
                
                // Safety timeout check (should not be needed if FFmpeg -t works)
                if ($timeout > 0 && (time() - $startTime) >= ($timeout + 3)) {
                    Log::warning("FFmpeg process exceeded expected timeout, force stopping", [
                        'elapsed' => time() - $startTime,
                        'expected_timeout' => $timeout,
                        'bytes_sent' => $totalBytes
                    ]);
                    $process->stop();
                    break;
                }
                
                // Check if process is stalled (but give more time for finite streams)
                $stallTimeout = $timeout > 0 ? min(15, $timeout + 5) : 15;
                if ((time() - $lastOutput) > $stallTimeout) {
                    Log::warning("FFmpeg process seems stalled, stopping", [
                        'last_output' => $lastOutput,
                        'current_time' => time(),
                        'stall_timeout' => $stallTimeout,
                        'bytes_sent' => $totalBytes
                    ]);
                    $process->stop();
                    break;
                }
                
                // Small sleep to prevent busy waiting
                usleep(10000); // 10ms
            }
            
            // Check if process exited due to error
            if ($process->getExitCode() > 0) {
                Log::error("FFmpeg process exited with error", [
                    'exit_code' => $process->getExitCode(),
                    'error_output' => $process->getErrorOutput(),
                    'bytes_sent' => $totalBytes
                ]);
            }
            
            Log::info("FFmpeg process finished", [
                'exit_code' => $process->getExitCode(),
                'bytes_sent' => $totalBytes,
                'elapsed' => time() - $startTime
            ]);
            
            // Get any remaining output
            $remainingOutput = $process->getIncrementalOutput();
            if (!empty($remainingOutput)) {
                echo $remainingOutput;
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
            
            $exitCode = $process->getExitCode();
            $finalElapsed = time() - $startTime;
            
            if ($exitCode !== 0 && $exitCode !== null) {
                Log::error("FFmpeg continuous stream failed", [
                    'exit_code' => $exitCode,
                    'error' => $process->getErrorOutput(),
                    'elapsed' => $finalElapsed,
                    'timeout' => $timeout
                ]);
            } else {
                Log::info("FFmpeg continuous stream completed", [
                    'duration' => $finalElapsed,
                    'timeout' => $timeout,
                    'exit_code' => $exitCode
                ]);
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

        $segmentDuration = 4; // 4 second segments
        $totalSegments = $timeout > 0 ? ceil($timeout / $segmentDuration) : 10; // For infinite, show 10 segments initially
        
        $playlist = "#EXTM3U\n";
        $playlist .= "#EXT-X-VERSION:3\n";
        $playlist .= "#EXT-X-TARGETDURATION:{$segmentDuration}\n";
        $playlist .= "#EXT-X-MEDIA-SEQUENCE:0\n";
        
        if ($timeout > 0) {
            $playlist .= "#EXT-X-PLAYLIST-TYPE:VOD\n";
        }
        
        // Generate segments
        for ($i = 0; $i < $totalSegments; $i++) {
            $segmentTimeout = $timeout > 0 ? min($segmentDuration, $timeout - ($i * $segmentDuration)) : $segmentDuration;
            if ($segmentTimeout <= 0) break;
            
            $playlist .= "#EXTINF:{$segmentTimeout}.0,\n";
            $playlist .= route('stream.test.segment', ['timeout' => $timeout, 'segment' => $i]) . "\n";
        }
        
        if ($timeout > 0) {
            $playlist .= "#EXT-X-ENDLIST\n";
        }
        
        return response($playlist)
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0')
            ->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * Generate a specific segment for HLS streaming
     * 
     * @param Request $request
     * @param int $timeout
     * @param int $segment
     * @return StreamedResponse
     */
    public function testSegment(Request $request, int $timeout = 0, int $segment = 0): StreamedResponse
    {
        // Validate parameters
        if ($timeout < 0 || $segment < 0) {
            abort(400, 'Invalid parameters');
        }

        $segmentDuration = 4; // 4 seconds per segment
        $segmentStart = $segment * $segmentDuration;
        
        // If timeout is set and this segment would exceed it, calculate actual duration
        if ($timeout > 0 && $segmentStart >= $timeout) {
            abort(404, 'Segment not found');
        }
        
        $actualDuration = $timeout > 0 ? min($segmentDuration, $timeout - $segmentStart) : $segmentDuration;
        
        $headers = [
            'Content-Type' => 'video/mp2t',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Connection' => 'keep-alive',
            'Access-Control-Allow-Origin' => '*',
        ];

        return new StreamedResponse(function () use ($timeout, $segment, $segmentStart, $actualDuration) {
            $this->generateSegment($timeout, $segment, $segmentStart, $actualDuration);
        }, 200, $headers);
    }

    /**
     * Generate a single video segment
     * 
     * @param int $timeout
     * @param int $segment
     * @param int $segmentStart
     * @param int $actualDuration
     * @return void
     */
    private function generateSegment(int $timeout, int $segment, int $segmentStart, int $actualDuration): void
    {
        Log::info("Generating segment", [
            'timeout' => $timeout,
            'segment' => $segment,
            'segment_start' => $segmentStart,
            'duration' => $actualDuration
        ]);

        if ($this->isFFmpegAvailable()) {
            $this->generateFFmpegSegment($timeout, $segment, $segmentStart, $actualDuration);
        } else {
            $this->generateBasicSegment($timeout, $segment, $segmentStart, $actualDuration);
        }
    }

    /**
     * Generate a segment using FFmpeg
     * 
     * @param int $timeout
     * @param int $segment
     * @param int $segmentStart
     * @param int $actualDuration
     * @return void
     */
    private function generateFFmpegSegment(int $timeout, int $segment, int $segmentStart, int $actualDuration): void
    {
        try {
            // Calculate what text to show
            if ($timeout > 0) {
                // Countdown from total timeout
                $displayTime = max(0, $timeout - $segmentStart);
                $timeText = sprintf("Countdown: %02d:%02d", floor($displayTime / 60), $displayTime % 60);
            } else {
                // Runtime counter
                $displayTime = $segmentStart;
                $timeText = sprintf("Runtime: %02d:%02d", floor($displayTime / 60), $displayTime % 60);
            }

            // Create FFmpeg command for a single segment
            $filterText = str_replace(['"', "'", ':', '\n'], ['\"', "\'", '\\:', '\\n'], $timeText . "\\nSegment: " . $segment);
            
            $cmd = [
                'ffmpeg',
                '-f', 'lavfi',
                '-i', 'testsrc2=size=1280x720:rate=25', // 720p test pattern at 25fps
                '-f', 'lavfi', 
                '-i', 'sine=frequency=1000:sample_rate=48000', // 1kHz tone
                '-filter_complex', "[0:v]drawtext=text='{$filterText}':fontsize=48:fontcolor=white:x=(w-tw)/2:y=(h-th)/2:box=1:boxcolor=black@0.5[v]",
                '-map', '[v]',
                '-map', '1:a',
                '-c:v', 'libx264',
                '-preset', 'ultrafast',
                '-tune', 'zerolatency',
                '-profile:v', 'baseline',
                '-level', '3.0',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'aac',
                '-b:a', '128k',
                '-t', (string)$actualDuration, // Duration of this segment
                '-f', 'mpegts',
                'pipe:1' // Output to stdout
            ];

            Log::info("Starting FFmpeg segment", ['cmd' => implode(' ', $cmd)]);

            $process = new Process($cmd);
            $process->setTimeout($actualDuration + 10);
            
            // Start the process
            $process->start();
            
            // Stream output as it becomes available
            $buffer = '';
            $lastOutput = time();
            
            while ($process->isRunning()) {
                // Read available output
                $output = $process->getIncrementalOutput();
                
                if (!empty($output)) {
                    echo $output;
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                    $lastOutput = time();
                }
                
                // Check if connection is still alive
                if (connection_aborted()) {
                    $process->stop();
                    Log::info("Segment stream connection aborted", ['segment' => $segment]);
                    break;
                }
                
                // Check if process seems stalled
                if ((time() - $lastOutput) > 15) {
                    Log::warning("FFmpeg segment process seems stalled", ['segment' => $segment]);
                    $process->stop();
                    break;
                }
                
                // Small sleep to prevent busy waiting
                usleep(10000); // 10ms
            }
            
            // Get any remaining output
            $remainingOutput = $process->getIncrementalOutput();
            if (!empty($remainingOutput)) {
                echo $remainingOutput;
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
            
            $exitCode = $process->getExitCode();
            if ($exitCode !== 0) {
                Log::error("FFmpeg segment failed", [
                    'segment' => $segment,
                    'exit_code' => $exitCode,
                    'error' => $process->getErrorOutput()
                ]);
            } else {
                Log::info("FFmpeg segment completed", ['segment' => $segment]);
            }
            
        } catch (\Exception $e) {
            Log::error("FFmpeg segment error", [
                'error' => $e->getMessage(),
                'segment' => $segment
            ]);
            
            // Fallback to basic segment
            $this->generateBasicSegment($timeout, $segment, $segmentStart, $actualDuration);
        }
    }

    /**
     * Generate a basic fallback segment
     * 
     * @param int $timeout
     * @param int $segment
     * @param int $segmentStart
     * @param int $actualDuration
     * @return void
     */
    private function generateBasicSegment(int $timeout, int $segment, int $segmentStart, int $actualDuration): void
    {
        // Calculate display time
        if ($timeout > 0) {
            $displayTime = max(0, $timeout - $segmentStart);
            $timeText = sprintf("Countdown: %02d:%02d", floor($displayTime / 60), $displayTime % 60);
        } else {
            $displayTime = $segmentStart;
            $timeText = sprintf("Runtime: %02d:%02d", floor($displayTime / 60), $displayTime % 60);
        }

        // Generate TS packets for this segment duration
        $packetsPerSecond = 250; // Approximate packets per second for reasonable bitrate
        $totalPackets = $actualDuration * $packetsPerSecond;
        
        for ($i = 0; $i < $totalPackets; $i++) {
            $packet = $this->createTsPacket($timeText, $segment, $i);
            echo $packet;
            
            // Throttle output to simulate real-time
            if ($i % 50 == 0) {
                usleep(20000); // 20ms delay every 50 packets
                
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                
                if (connection_aborted()) {
                    Log::info("Basic segment connection aborted", ['segment' => $segment]);
                    break;
                }
            }
        }
    }

    /**
     * Create a single TS packet
     * 
     * @param string $timeText
     * @param int $segment
     * @param int $packetNum
     * @return string
     */
    private function createTsPacket(string $timeText, int $segment, int $packetNum): string
    {
        $packet = '';
        
        // Sync byte (0x47)
        $packet .= chr(0x47);
        
        // Transport Error Indicator, Payload Unit Start Indicator, Transport Priority, PID
        $pid = 0x0100 + ($packetNum % 8); // Vary PID slightly
        $packet .= pack('n', $pid);
        
        // Scrambling control, Adaptation field control, Continuity counter
        $continuityCounter = $packetNum % 16;
        $packet .= chr(0x10 | $continuityCounter);
        
        // Payload (184 bytes remaining)
        $payload = sprintf("Test Stream - %s - Segment: %d - Packet: %d - Time: %s", 
            $timeText, $segment, $packetNum, date('H:i:s'));
        
        // Pad to 184 bytes
        $payload = str_pad($payload, 184, "\xFF");
        $packet .= substr($payload, 0, 184);
        
        return $packet;
    }
}
