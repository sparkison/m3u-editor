<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class ProxyFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'proxy';
    }
}
