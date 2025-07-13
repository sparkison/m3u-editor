<?php

namespace App\Console\Commands;

use App\Models\SharedStream;
use App\Services\ProxyService;
use App\Services\SharedStreamService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class FlushFfmpegProcessCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:flush-ffmpeg-process-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all tracked FFmpeg processes from the cache';

    /**
     * Execute the console command.
     */
    public function handle(SharedStreamService $sharedStreamService): void
    {
        $this->info('🧹 Cleaning up FFmpeg process cache...');

        // Flush the Redis store (FFmpeg processes mgmt., cache, etc.)
        Redis::flushdb();

        // Clean shared streams
        collect(
            $sharedStreamService->getAllActiveStreams()
        )->each(fn(array $stream, $streamKey) => $sharedStreamService->cleanupStream($streamKey, true));
    }
}
