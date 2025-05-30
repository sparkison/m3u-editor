<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getProxyUrlForChannel(string $id, string $format = 'ts')
 * @method static string getProxyUrlForEpisode(string $id, string $format = 'ts')
 */
class ProxyFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'proxy';
    }
}
