<?php

namespace App\Events;

use App\Models\Playlist;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlaylistCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     * 
     * @param Playlist $playlist
     * @param bool $isNew
     */
    public function __construct(
        public Playlist $playlist,
        public bool $isNew = false
    ) {}
}
