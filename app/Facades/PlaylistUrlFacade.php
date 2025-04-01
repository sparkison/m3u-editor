<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class PlaylistUrlFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'playlistUrl';
    }
}
