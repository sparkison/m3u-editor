<?php

namespace App\Events;

use App\Models\Epg;
use App\Models\Playlist;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     * 
     * @param Playlist|null $playlist
     * @param Epg|null $epg
     */
    public function __construct(
        public ?Playlist $playlist = null,
        public ?Epg $epg = null,
    ) {}
}
