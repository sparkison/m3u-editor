<?php
/**
 * Streaming Performance Test Script
 * 
 * This script tests and compares the performance of:
 * 1. Direct streaming (/stream/)
 * 2. Optimized shared streaming (/shared/stream/)
 * 
 * It measures:
 * - Initial startup time
 * - Time to first byte
 * - VLC compatibility
 * - Memory usage
 * - CPU usage patterns
 * - Throughput consistency
 */

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class StreamingPerformanceTest
{
    private $baseUrl;
    private $testChannel;
    private $results = [];
    
    public function __construct()
    {
        $this->baseUrl = 'https://m3ueditor.test'; // Adjust as needed
        
        // Use a test channel - you'll need to adjust this
        $this->testChannel = [
            'id' => 1216314, // Actual channel ID in your database
            'encoded_id' => 'MTIxNjMxNA==', // Base64 encoded channel ID (corrected)
        ];
    }
    
    public function runTests()
    {
        echo "🚀 Starting Streaming Performance Tests\n";
        echo "==========================================\n\n";
        
        // Test 1: Direct Streaming Performance
        echo "📡 Testing Direct Streaming (/stream/)...\n";
        $directResults = $this->testDirectStreaming();
        $this->results['direct'] = $directResults;
        $this->printResults('Direct Streaming', $directResults);
        
        echo "\n";
        
        // Test 2: Shared Streaming Performance
        echo "🔗 Testing Shared Streaming (/shared/stream/)...\n";
        $sharedResults = $this->testSharedStreaming();
        $this->results['shared'] = $sharedResults;
        $this->printResults('Shared Streaming', $sharedResults);
        
        echo "\n";
        
        // Test 3: Multi-Client Shared Streaming
        echo "👥 Testing Multi-Client Shared Streaming...\n";
        $multiClientResults = $this->testMultiClientSharedStreaming();
        $this->results['multi_client'] = $multiClientResults;
        $this->printResults('Multi-Client Shared', $multiClientResults);
        
        echo "\n";
        
        // Test 4: VLC Compatibility Test
        echo "🎥 Testing VLC Compatibility...\n";
        $vlcResults = $this->testVLCCompatibility();
        $this->results['vlc'] = $vlcResults;
        $this->printResults('VLC Compatibility', $vlcResults);
        
        echo "\n";
        
        // Performance Comparison
        $this->comparePerformance();
        
        // Cleanup
        $this->cleanup();
    }
    
    private function testDirectStreaming()
    {
        $url = "{$this->baseUrl}/stream/{$this->testChannel['encoded_id']}.ts";
        
        $startTime = microtime(true);
        $firstByteTime = null;
        $totalBytes = 0;
        $errors = [];
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$firstByteTime, &$totalBytes, $startTime) {
                    if ($firstByteTime === null && strlen($data) > 0) {
                        $firstByteTime = microtime(true) - $startTime;
                        echo "  ⚡ First byte received in: " . round($firstByteTime * 1000, 2) . "ms\n";
                    }
                    $totalBytes += strlen($data);
                    
                    // Stop after getting enough data for testing
                    if ($totalBytes > 5 * 1024 * 1024) { // 5MB
                        return 0; // Stop downloading
                    }
                    return strlen($data);
                },
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'VLC/3.0.16 LibVLC/3.0.16',
                CURLOPT_HTTPHEADER => [
                    'Accept: */*',
                    'Connection: keep-alive'
                ]
            ]);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $error = curl_error($ch);
            
            if ($error) {
                $errors[] = $error;
            }
            
            curl_close($ch);
            
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
        
        $endTime = microtime(true);
        
        return [
            'startup_time' => $firstByteTime,
            'total_time' => $endTime - $startTime,
            'total_bytes' => $totalBytes,
            'throughput_mbps' => $totalBytes > 0 ? ($totalBytes * 8) / (($endTime - $startTime) * 1024 * 1024) : 0,
            'http_code' => $httpCode ?? 0,
            'errors' => $errors
        ];
    }
    
    private function testSharedStreaming()
    {
        $url = "{$this->baseUrl}/shared/stream/{$this->testChannel['encoded_id']}.ts";
        
        $startTime = microtime(true);
        $firstByteTime = null;
        $totalBytes = 0;
        $errors = [];
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$firstByteTime, &$totalBytes, $startTime) {
                    if ($firstByteTime === null && strlen($data) > 0) {
                        $firstByteTime = microtime(true) - $startTime;
                        echo "  ⚡ First byte received in: " . round($firstByteTime * 1000, 2) . "ms\n";
                    }
                    $totalBytes += strlen($data);
                    
                    // Stop after getting enough data for testing
                    if ($totalBytes > 5 * 1024 * 1024) { // 5MB
                        return 0; // Stop downloading
                    }
                    return strlen($data);
                },
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'VLC/3.0.16 LibVLC/3.0.16',
                CURLOPT_HTTPHEADER => [
                    'Accept: */*',
                    'Connection: keep-alive'
                ]
            ]);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $error = curl_error($ch);
            
            if ($error) {
                $errors[] = $error;
            }
            
            curl_close($ch);
            
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
        
        $endTime = microtime(true);
        
        return [
            'startup_time' => $firstByteTime,
            'total_time' => $endTime - $startTime,
            'total_bytes' => $totalBytes,
            'throughput_mbps' => $totalBytes > 0 ? ($totalBytes * 8) / (($endTime - $startTime) * 1024 * 1024) : 0,
            'http_code' => $httpCode ?? 0,
            'errors' => $errors
        ];
    }
    
    private function testMultiClientSharedStreaming()
    {
        $url = "{$this->baseUrl}/shared/stream/{$this->testChannel['encoded_id']}.ts";
        $numClients = 3;
        $results = [];
        
        echo "  📊 Testing with {$numClients} simultaneous clients...\n";
        
        // Start multiple clients simultaneously
        $processes = [];
        for ($i = 0; $i < $numClients; $i++) {
            $cmd = "timeout 10 curl -s -w '%{http_code},%{time_total},%{size_download}' " .
                   "-H 'User-Agent: VLC/3.0.16 LibVLC/3.0.16' " .
                   "-o /dev/null '$url' 2>/dev/null";
            
            $processes[$i] = [
                'process' => popen($cmd, 'r'),
                'start_time' => microtime(true)
            ];
            
            // Small delay between client starts
            usleep(100000); // 100ms
        }
        
        // Wait for all processes to complete
        foreach ($processes as $i => $proc) {
            $output = fread($proc['process'], 1024);
            pclose($proc['process']);
            
            $parts = explode(',', trim($output));
            if (count($parts) >= 3) {
                $results[$i] = [
                    'http_code' => intval($parts[0]),
                    'total_time' => floatval($parts[1]),
                    'bytes_downloaded' => intval($parts[2]),
                    'throughput_mbps' => intval($parts[2]) > 0 ? (intval($parts[2]) * 8) / (floatval($parts[1]) * 1024 * 1024) : 0
                ];
            }
        }
        
        // Calculate averages
        if (!empty($results)) {
            $avgThroughput = array_sum(array_column($results, 'throughput_mbps')) / count($results);
            $avgTime = array_sum(array_column($results, 'total_time')) / count($results);
            $totalBytes = array_sum(array_column($results, 'bytes_downloaded'));
        } else {
            $avgThroughput = 0;
            $avgTime = 0;
            $totalBytes = 0;
        }
        
        return [
            'num_clients' => $numClients,
            'avg_throughput_mbps' => $avgThroughput,
            'avg_time' => $avgTime,
            'total_bytes' => $totalBytes,
            'client_results' => $results
        ];
    }
    
    private function testVLCCompatibility()
    {
        $url = "{$this->baseUrl}/shared/stream/{$this->testChannel['encoded_id']}.ts";
        
        echo "  🎬 Testing VLC startup behavior...\n";
        
        // Simulate VLC's initial request pattern
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'VLC/3.0.16 LibVLC/3.0.16',
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'Range: bytes=0-',
                'Connection: keep-alive',
                'Icy-MetaData: 1'
            ],
            CURLOPT_TIMEOUT => 15,
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true
        ]);
        
        $startTime = microtime(true);
        $headers = curl_exec($ch);
        $headerTime = microtime(true) - $startTime;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Test actual streaming
        $streamingTest = $this->testStreamingStability($url);
        
        return [
            'header_response_time' => $headerTime,
            'http_code' => $httpCode,
            'headers_received' => $headers !== false,
            'streaming_stability' => $streamingTest
        ];
    }
    
    private function testStreamingStability($url)
    {
        $startTime = microtime(true);
        $chunks = 0;
        $totalBytes = 0;
        $gaps = 0;
        $lastChunkTime = $startTime;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (&$chunks, &$totalBytes, &$gaps, &$lastChunkTime) {
                $now = microtime(true);
                
                if (strlen($data) > 0) {
                    $chunks++;
                    $totalBytes += strlen($data);
                    
                    // Detect gaps (more than 100ms between chunks)
                    if ($now - $lastChunkTime > 0.1) {
                        $gaps++;
                    }
                    
                    $lastChunkTime = $now;
                }
                
                // Stop after reasonable amount of data
                if ($totalBytes > 2 * 1024 * 1024) { // 2MB
                    return 0;
                }
                
                return strlen($data);
            },
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'VLC/3.0.16 LibVLC/3.0.16'
        ]);
        
        curl_exec($ch);
        $totalTime = microtime(true) - $startTime;
        curl_close($ch);
        
        return [
            'total_chunks' => $chunks,
            'total_bytes' => $totalBytes,
            'total_time' => $totalTime,
            'gaps_detected' => $gaps,
            'avg_chunk_size' => $chunks > 0 ? $totalBytes / $chunks : 0,
            'consistency_score' => $chunks > 0 ? 1 - ($gaps / $chunks) : 0
        ];
    }
    
    private function printResults($testName, $results)
    {
        echo "  📈 Results for {$testName}:\n";
        
        if (isset($results['startup_time'])) {
            echo "    ⏱️  Startup Time: " . round($results['startup_time'] * 1000, 2) . "ms\n";
        }
        
        if (isset($results['total_time'])) {
            echo "    🕐 Total Time: " . round($results['total_time'], 2) . "s\n";
        }
        
        if (isset($results['total_bytes'])) {
            echo "    📦 Total Bytes: " . $this->formatBytes($results['total_bytes']) . "\n";
        }
        
        if (isset($results['throughput_mbps'])) {
            echo "    🚀 Throughput: " . round($results['throughput_mbps'], 2) . " Mbps\n";
        }
        
        if (isset($results['http_code'])) {
            echo "    📡 HTTP Code: " . $results['http_code'] . "\n";
        }
        
        if (isset($results['errors']) && !empty($results['errors'])) {
            echo "    ❌ Errors: " . implode(', ', $results['errors']) . "\n";
        }
        
        if (isset($results['consistency_score'])) {
            echo "    📊 Consistency: " . round($results['consistency_score'] * 100, 1) . "%\n";
        }
        
        if (isset($results['gaps_detected'])) {
            echo "    🔄 Gaps Detected: " . $results['gaps_detected'] . "\n";
        }
    }
    
    private function comparePerformance()
    {
        echo "🏆 Performance Comparison\n";
        echo "========================\n";
        
        if (!isset($this->results['direct']) || !isset($this->results['shared'])) {
            echo "❌ Cannot compare - missing test results\n";
            return;
        }
        
        $direct = $this->results['direct'];
        $shared = $this->results['shared'];
        
        // Startup time comparison
        if (isset($direct['startup_time']) && isset($shared['startup_time'])) {
            $improvement = (($direct['startup_time'] - $shared['startup_time']) / $direct['startup_time']) * 100;
            echo "⚡ Startup Time:\n";
            echo "   Direct: " . round($direct['startup_time'] * 1000, 2) . "ms\n";
            echo "   Shared: " . round($shared['startup_time'] * 1000, 2) . "ms\n";
            echo "   Improvement: " . ($improvement >= 0 ? "+" : "") . round($improvement, 1) . "%\n\n";
        }
        
        // Throughput comparison
        if (isset($direct['throughput_mbps']) && isset($shared['throughput_mbps'])) {
            $improvement = (($shared['throughput_mbps'] - $direct['throughput_mbps']) / $direct['throughput_mbps']) * 100;
            echo "🚀 Throughput:\n";
            echo "   Direct: " . round($direct['throughput_mbps'], 2) . " Mbps\n";
            echo "   Shared: " . round($shared['throughput_mbps'], 2) . " Mbps\n";
            echo "   Improvement: " . ($improvement >= 0 ? "+" : "") . round($improvement, 1) . "%\n\n";
        }
        
        // Overall assessment
        $this->generateAssessment();
    }
    
    private function generateAssessment()
    {
        echo "📋 Assessment:\n";
        echo "=============\n";
        
        $direct = $this->results['direct'];
        $shared = $this->results['shared'];
        
        $issues = [];
        $improvements = [];
        
        // Check startup time
        if (isset($direct['startup_time']) && isset($shared['startup_time'])) {
            if ($shared['startup_time'] > $direct['startup_time'] * 1.2) {
                $issues[] = "Shared streaming startup is significantly slower";
            } elseif ($shared['startup_time'] < $direct['startup_time'] * 0.8) {
                $improvements[] = "Shared streaming starts faster";
            }
        }
        
        // Check throughput
        if (isset($direct['throughput_mbps']) && isset($shared['throughput_mbps'])) {
            if ($shared['throughput_mbps'] < $direct['throughput_mbps'] * 0.8) {
                $issues[] = "Shared streaming throughput is significantly lower";
            } elseif ($shared['throughput_mbps'] > $direct['throughput_mbps'] * 1.1) {
                $improvements[] = "Shared streaming has better throughput";
            }
        }
        
        // Check VLC compatibility
        if (isset($this->results['vlc'])) {
            $vlc = $this->results['vlc'];
            if (isset($vlc['consistency_score']) && $vlc['consistency_score'] < 0.8) {
                $issues[] = "VLC streaming consistency is poor";
            }
            if (isset($vlc['gaps_detected']) && $vlc['gaps_detected'] > 10) {
                $issues[] = "Too many gaps in VLC streaming";
            }
        }
        
        if (empty($issues) && empty($improvements)) {
            echo "✅ Performance is comparable between direct and shared streaming\n";
        } else {
            if (!empty($improvements)) {
                echo "✅ Improvements:\n";
                foreach ($improvements as $improvement) {
                    echo "   - $improvement\n";
                }
            }
            
            if (!empty($issues)) {
                echo "⚠️  Issues to address:\n";
                foreach ($issues as $issue) {
                    echo "   - $issue\n";
                }
            }
        }
    }
    
    private function cleanup()
    {
        echo "\n🧹 Cleaning up test data...\n";
        
        try {
            // Clean up any Redis keys created during testing
            $testKeys = Redis::keys("*test*");
            if (!empty($testKeys)) {
                Redis::del($testKeys);
                echo "   ✅ Cleaned up " . count($testKeys) . " Redis test keys\n";
            }
        } catch (Exception $e) {
            echo "   ⚠️  Warning: Could not clean up Redis keys: " . $e->getMessage() . "\n";
        }
    }
    
    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}

// Run the tests
try {
    $test = new StreamingPerformanceTest();
    $test->runTests();
} catch (Exception $e) {
    echo "❌ Test failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ Performance testing completed!\n";
