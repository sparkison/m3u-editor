<?php

namespace App\Enums;

enum PlaylistSourceType: string
{
    case Xtream = 'xtream';
    case M3u = 'm3u';
    case Local = 'local';
}