<?php

namespace App\Console\Commands;

use App\Services\ProxyService;
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
    public function handle()
    {
        $this->info('🗑️ Flushing FFmpeg process cache...');

        // Flush the Redis cache for FFmpeg processes
        Redis::connection('default')->flushdb();
    }
}
