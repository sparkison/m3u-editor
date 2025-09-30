<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getProxyUrlForChannel(string $id, string $format = 'ts', bool $preview = false)
 * @method static string getProxyUrlForEpisode(string $id, string $format = 'ts', bool $preview = false)
 */
class ProxyFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'proxy';
    }
}
