<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Register schedules
 */

// Check for updates
Schedule::command('app:update-check')
    ->daily();

// Cleanup old/stale job batches
Schedule::command('app:flush-jobs-table')
    ->twiceDaily();

// Refresh playlists
Schedule::command('app:refresh-playlist')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Refresh EPG
Schedule::command('app:refresh-epg')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Prune stale processes
Schedule::command('app:hls-prune')
    ->everyFifteenSeconds()
    ->withoutOverlapping()
    ->runInBackground();
