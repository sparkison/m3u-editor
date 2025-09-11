<?php

namespace Tests;

use Illuminate\Foundation\Application;

trait CreatesApplication
{
    public function createApplication(): Application
    {
        putenv('BROADCAST_CONNECTION=null');
        $_ENV['BROADCAST_CONNECTION'] = 'null';
        $_SERVER['BROADCAST_CONNECTION'] = 'null';

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }
}

