<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class LogoFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'logo.service';
    }
}
