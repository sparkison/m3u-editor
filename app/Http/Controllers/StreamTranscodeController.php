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
        
        if (!$url) {
            return response()->json(['error' => 'URL is required'], 400);
        }
        
        // Validate and sanitize the URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['error' => 'Invalid URL'], 400);
        }
        
        Log::info('Starting stream transcode', [
            'url' => $url,
            'format' => $format,
            'user_agent' => $request->userAgent()
        ]);
        
        // Determine if we need transcoding based on the file extension
        $needsTranscoding = $this->needsTranscoding($url, $format);
        
        if (!$needsTranscoding) {
            // Direct proxy without transcoding
            return $this->proxyStream($url, $request);
        }
        
        // Transcode the stream
        return $this->transcodeStream($url, $request);
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
        $needsTranscodingExtensions = ['mkv', 'avi', 'mov', 'flv', 'wmv', 'webm'];
        
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
    
    private function transcodeStream($url, Request $request)
    {
        return new StreamedResponse(function () use ($url) {
            // FFmpeg command for efficient transcoding to MP4/H.264/AAC
            $ffmpegCmd = [
                'ffmpeg',
                '-i', $url,
                '-c:v', 'libx264',           // H.264 video codec
                '-preset', 'ultrafast',      // Fastest encoding preset
                '-tune', 'zerolatency',      // Low latency tuning
                '-c:a', 'aac',               // AAC audio codec
                '-b:a', '128k',              // Audio bitrate
                '-movflags', 'frag_keyframe+empty_moov+faststart', // Optimize for streaming
                '-f', 'mp4',                 // MP4 container
                '-fflags', '+genpts',        // Generate presentation timestamps
                '-avoid_negative_ts', 'make_zero',
                '-y',                        // Overwrite output
                'pipe:1'                     // Output to stdout
            ];
            
            Log::info('Starting FFmpeg transcode', ['command' => implode(' ', $ffmpegCmd)]);
            
            $process = new Process($ffmpegCmd);
            $process->setTimeout(null); // No timeout for streaming
            
            try {
                $process->start();
                
                foreach ($process as $type => $data) {
                    if ($type === Process::OUT) {
                        echo $data;
                        if (ob_get_level()) {
                            ob_flush();
                        }
                        flush();
                    } elseif ($type === Process::ERR) {
                        Log::warning('FFmpeg stderr', ['data' => $data]);
                    }
                }
                
                $process->wait();
                
                if (!$process->isSuccessful()) {
                    Log::error('FFmpeg process failed', [
                        'exit_code' => $process->getExitCode(),
                        'error' => $process->getErrorOutput()
                    ]);
                }
                
            } catch (\Exception $e) {
                Log::error('FFmpeg transcoding error', ['error' => $e->getMessage()]);
            }
            
        }, 200, [
            'Content-Type' => 'video/mp4',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => '*',
            'X-Transcoded' => 'true'
        ]);
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
                    'streams' => []
                ];
                
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
                        $metadata['streams'][] = [
                            'type' => 'audio',
                            'codec' => $stream['codec_name'] ?? 'unknown',
                            'channels' => $stream['channels'] ?? null,
                            'sample_rate' => $stream['sample_rate'] ?? null,
                            'bitrate' => $stream['bit_rate'] ?? null,
                            'language' => $stream['tags']['language'] ?? 'unknown'
                        ];
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
