<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ...

// Register schedule
Schedule::command('app:refresh-playlist')
    ->everyFiveMinutes();

