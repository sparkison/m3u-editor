<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Register schedules
 */

// Refresh playlists
Schedule::command('app:refresh-playlist')
    ->everyFiveMinutes();

// Refresh EPG
Schedule::command('app:refresh-epg')
    ->everyFiveMinutes();

// Cleanup old/stale job batches
Schedule::command('app:flush-jobs-table')
    ->twiceDaily();
