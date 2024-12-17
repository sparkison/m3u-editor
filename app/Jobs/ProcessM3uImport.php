<?php

namespace App\Jobs;

use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

use zikwall\m3ucontentparser\M3UContentParser;
use zikwall\m3ucontentparser\M3UItem;

class ProcessM3uImport implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     * 
     * @param Playlist $playlist
     */
    public function __construct(
        public Playlist $playlist
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $parser = new M3UContentParser($this->playlist->url);
        $parser->parse();
        foreach ($parser->all() as $item) {
            /**
             * @var $item M3UItem
             */
            dump($item);
        }
    }
}
