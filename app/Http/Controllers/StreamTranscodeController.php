<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamTranscodeController extends Controller
{
    public function transcode(Request $request)
    {
        $url = $request->input('url');
        $format = $request->input('format', 'auto');
        $audioTrack = $request->input('audio_track', 0); // Audio track selection
        $seekTo = $request->input('seek', null); // Seek position in seconds
        
        if (!$url) {
            return response()->json(['error' => 'URL is required'], 400);
        }
        
        // Validate and sanitize the URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['error' => 'Invalid URL'], 400);
        }
        
        // Remove execution time limit for streaming
        set_time_limit(0);
        ignore_user_abort(true);
        
        Log::info('Starting stream transcode', [
            'url' => $url,
            'format' => $format,
            'audio_track' => $audioTrack,
            'seek' => $seekTo,
            'user_agent' => $request->userAgent()
        ]);
        
        // Determine if we need transcoding based on the file extension
        $needsTranscoding = $this->needsTranscoding($url, $format);
        
        if (!$needsTranscoding) {
            // Direct proxy without transcoding
            return $this->proxyStream($url, $request);
        }
        
        // Transcode the stream with audio track selection and seeking
        return $this->transcodeStream($url, $request, $audioTrack, $seekTo);
    }
    
    private function needsTranscoding($url, $format)
    {
        // Parse the URL to get file extension
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        // Check if it's already HLS or TS (no transcoding needed)
        if ($format === 'hls' || $format === 'ts' || $format === 'mpegts') {
            return false;
        }
        
        if (str_contains($url, '.m3u8') || str_contains($url, '.ts')) {
            return false;
        }
        
        // File types that need transcoding for web compatibility
        $needsTranscodingExtensions = ['mkv', 'avi', 'mov', 'flv', 'wmv', 'asf', 'rm', 'rmvb'];
        
        // MP4 might need transcoding depending on codec
        if ($extension === 'mp4') {
            // We'll probe the file to determine if transcoding is needed
            return $this->probeNeedsTranscoding($url);
        }
        
        return in_array($extension, $needsTranscodingExtensions);
    }
    
    private function probeNeedsTranscoding($url)
    {
        try {
            // Quick probe to check codecs
            $probeCmd = [
                'ffprobe',
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_streams',
                '-select_streams', 'v:0',
                $url
            ];
            
            $process = new Process($probeCmd);
            $process->setTimeout(10); // 10 second timeout for probe
            $process->run();
            
            if ($process->isSuccessful()) {
                $output = json_decode($process->getOutput(), true);
                $videoStream = $output['streams'][0] ?? null;
                
                if ($videoStream) {
                    $codec = $videoStream['codec_name'] ?? '';
                    // H.264 and H.265 in MP4 usually work fine in browsers
                    if (in_array($codec, ['h264', 'h265', 'hevc'])) {
                        return false;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to probe stream', ['url' => $url, 'error' => $e->getMessage()]);
        }
        
        // Default to transcoding if we can't determine
        return true;
    }
    
    private function proxyStream($url, Request $request)
    {
        return new StreamedResponse(function () use ($url, $request) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: ' . ($request->userAgent() ?: 'M3U-Editor/1.0'),
                        'Accept: */*',
                        'Connection: close'
                    ],
                    'timeout' => 30
                ]
            ]);
            
            $stream = fopen($url, 'r', false, $context);
            
            if ($stream) {
                while (!feof($stream)) {
                    echo fread($stream, 8192);
                    if (ob_get_level()) {
                        ob_flush();
                    }
                    flush();
                }
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'video/mp4',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
    
    private function transcodeStream($url, Request $request, $audioTrack = 0, $seekTo = null)
    {
        // Get stream metadata first to include in headers
        $metadata = $this->getStreamMetadata($url);
        $duration = $metadata['duration'] ?? null;
        
        return new StreamedResponse(function () use ($url, $audioTrack, $seekTo) {
            // First, probe to get available streams and validate audio track
            $availableAudioTracks = $this->getAvailableAudioTracks($url);
            $validAudioTrack = 0; // Default to first track
            
            if (!empty($availableAudioTracks)) {
                // Check if requested audio track exists
                $trackExists = false;
                foreach ($availableAudioTracks as $track) {
                    if ($track['index'] == $audioTrack) {
                        $validAudioTrack = $audioTrack;
                        $trackExists = true;
                        break;
                    }
                }
                
                if (!$trackExists) {
                    Log::warning("Requested audio track $audioTrack not found, using track 0");
                    $validAudioTrack = 0;
                }
            }
            
            // FFmpeg command for efficient transcoding to MP4/H.264/AAC with audio track selection
            $ffmpegCmd = [
                'ffmpeg',
                '-loglevel', 'warning',          // Reduce log verbosity
                '-i', $url,
            ];
            
            // Add seeking if specified (input seeking is more efficient)
            if ($seekTo !== null && is_numeric($seekTo) && $seekTo > 0) {
                array_splice($ffmpegCmd, 3, 0, ['-ss', (string)$seekTo]);
            }
            
            // Build the transcoding command
            $ffmpegCmd = array_merge($ffmpegCmd, [
                '-map', '0:v:0',                 // Map first video stream
                '-map', "0:a:$validAudioTrack",  // Map validated audio track
                '-c:v', 'libx264',               // H.264 video codec
                '-preset', 'fast',               // Better quality than ultrafast
                '-crf', '23',                    // Constant rate factor for quality
                '-c:a', 'aac',                   // AAC audio codec
                '-ac', '2',                      // Force stereo output for compatibility
                '-ar', '48000',                  // Set audio sample rate
                '-b:a', '128k',                  // Audio bitrate
                '-movflags', 'frag_keyframe+empty_moov+faststart', // Enable seeking
                '-f', 'mp4',                     // MP4 container
                '-fflags', '+genpts',            // Generate presentation timestamps
                '-avoid_negative_ts', 'make_zero',
                '-max_muxing_queue_size', '1024', // Handle complex audio conversion
                '-y',                            // Overwrite output
                'pipe:1'                         // Output to stdout
            ]);
            
            Log::info('Starting FFmpeg transcode', [
                'command' => implode(' ', $ffmpegCmd),
                'requested_audio_track' => $audioTrack,
                'actual_audio_track' => $validAudioTrack,
                'seek_position' => $seekTo
            ]);
            
            $process = new Process($ffmpegCmd);
            $process->setTimeout(null); // No timeout for streaming
            
            try {
                $process->start();
                
                // Set stream to non-blocking mode
                if ($process->isStarted()) {
                    $stdout = $process->getOutput();
                }
                
                foreach ($process as $type => $data) {
                    if ($type === Process::OUT) {
                        echo $data;
                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();
                        
                        // Check if client disconnected
                        if (connection_aborted()) {
                            Log::info('Client disconnected, stopping transcode');
                            $process->stop();
                            break;
                        }
                    } elseif ($type === Process::ERR) {
                        // Only log actual errors, not progress information
                        $trimmedData = trim($data);
                        if (strpos($trimmedData, 'frame=') === false && 
                            strpos($trimmedData, 'fps=') === false &&
                            strpos($trimmedData, 'bitrate=') === false &&
                            !empty($trimmedData)) {
                            Log::warning('FFmpeg stderr', ['data' => $trimmedData]);
                        }
                    }
                }
                
                $process->wait();
                
                if (!$process->isSuccessful()) {
                    $exitCode = $process->getExitCode();
                    $errorOutput = $process->getErrorOutput();
                    
                    // Don't log client disconnect errors (exit code 255 is often client disconnect)
                    if ($exitCode !== 255 || !connection_aborted()) {
                        Log::error('FFmpeg process failed', [
                            'exit_code' => $exitCode,
                            'error' => $errorOutput,
                            'requested_audio_track' => $audioTrack,
                            'actual_audio_track' => $validAudioTrack
                        ]);
                    }
                }
                
            } catch (\Exception $e) {
                Log::error('FFmpeg transcoding error', [
                    'error' => $e->getMessage(),
                    'requested_audio_track' => $audioTrack,
                    'actual_audio_track' => $validAudioTrack
                ]);
            }
            
        }, 200, [
            'Content-Type' => 'video/mp4',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
            'Accept-Ranges' => 'bytes',        // Enable seeking support
            'X-Transcoded' => 'true',
            'X-Audio-Track' => $audioTrack,
            'X-Content-Duration' => $duration ? (string)$duration : null,
        ]);
    }
    
    private function getAvailableAudioTracks($url)
    {
        try {
            $probeCmd = [
                'ffprobe',
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_streams',
                '-select_streams', 'a',
                $url
            ];
            
            $process = new Process($probeCmd);
            $process->setTimeout(10);
            $process->run();
            
            if ($process->isSuccessful()) {
                $data = json_decode($process->getOutput(), true);
                $audioTracks = [];
                $trackIndex = 0;
                
                foreach ($data['streams'] as $stream) {
                    if ($stream['codec_type'] === 'audio') {
                        $audioTracks[] = [
                            'index' => $trackIndex,
                            'codec' => $stream['codec_name'] ?? 'unknown',
                            'language' => $stream['tags']['language'] ?? 'unknown'
                        ];
                        $trackIndex++;
                    }
                }
                
                return $audioTracks;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to probe audio tracks', ['url' => $url, 'error' => $e->getMessage()]);
        }
        
        return [];
    }
    
    private function getStreamMetadata($url)
    {
        try {
            $probeCmd = [
                'ffprobe',
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_format',
                $url
            ];
            
            $process = new Process($probeCmd);
            $process->setTimeout(10);
            $process->run();
            
            if ($process->isSuccessful()) {
                $data = json_decode($process->getOutput(), true);
                return [
                    'duration' => $data['format']['duration'] ?? null,
                    'size' => $data['format']['size'] ?? null,
                    'bitrate' => $data['format']['bit_rate'] ?? null
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to probe stream metadata', ['url' => $url, 'error' => $e->getMessage()]);
        }
        
        return [];
    }
    
    public function probe(Request $request)
    {
        $url = $request->input('url');
        
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['error' => 'Invalid URL'], 400);
        }
        
        try {
            $probeCmd = [
                'ffprobe',
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_streams',
                '-show_format',
                $url
            ];
            
            $process = new Process($probeCmd);
            $process->setTimeout(15);
            $process->run();
            
            if ($process->isSuccessful()) {
                $data = json_decode($process->getOutput(), true);
                
                // Extract useful metadata
                $metadata = [
                    'duration' => $data['format']['duration'] ?? null,
                    'size' => $data['format']['size'] ?? null,
                    'bitrate' => $data['format']['bit_rate'] ?? null,
                    'streams' => [],
                    'audio_tracks' => []
                ];
                
                $audioTrackIndex = 0;
                foreach ($data['streams'] as $stream) {
                    if ($stream['codec_type'] === 'video') {
                        $metadata['streams'][] = [
                            'type' => 'video',
                            'codec' => $stream['codec_name'] ?? 'unknown',
                            'width' => $stream['width'] ?? null,
                            'height' => $stream['height'] ?? null,
                            'fps' => $this->parseFps($stream['r_frame_rate'] ?? null),
                            'bitrate' => $stream['bit_rate'] ?? null
                        ];
                    } elseif ($stream['codec_type'] === 'audio') {
                        $audioTrack = [
                            'type' => 'audio',
                            'index' => $audioTrackIndex,
                            'codec' => $stream['codec_name'] ?? 'unknown',
                            'channels' => $stream['channels'] ?? null,
                            'sample_rate' => $stream['sample_rate'] ?? null,
                            'bitrate' => $stream['bit_rate'] ?? null,
                            'language' => $stream['tags']['language'] ?? 'unknown',
                            'title' => $stream['tags']['title'] ?? null
                        ];
                        
                        $metadata['streams'][] = $audioTrack;
                        $metadata['audio_tracks'][] = $audioTrack;
                        $audioTrackIndex++;
                    }
                }
                
                return response()->json($metadata);
            } else {
                return response()->json(['error' => 'Failed to probe stream'], 500);
            }
            
        } catch (\Exception $e) {
            Log::error('Stream probe error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Probe failed'], 500);
        }
    }
    
    private function parseFps($fpsString)
    {
        if (!$fpsString || $fpsString === '0/0') {
            return null;
        }
        
        if (str_contains($fpsString, '/')) {
            [$num, $den] = explode('/', $fpsString);
            return $den != 0 ? round($num / $den, 2) : null;
        }
        
        return (float) $fpsString;
    }
}
