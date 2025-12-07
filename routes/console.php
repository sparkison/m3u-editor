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
    ->everyMinute()
    ->withoutOverlapping();

// Refresh EPG
Schedule::command('app:refresh-epg')
    ->everyMinute()
    ->withoutOverlapping();

// EPG cache health
Schedule::command('app:epg-cache-health-check')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Check backup
Schedule::command('app:run-scheduled-backups')
    ->everyMinute()
    ->withoutOverlapping();

// Cleanup logos
Schedule::command('app:logo-cleanup --force')
    ->daily()
    ->withoutOverlapping();

// Prune failed jobs
Schedule::command('queue:prune-failed --hours=48')
    ->daily();

// Prune old notifications
Schedule::command('app:prune-old-notifications --days=30')
    ->daily();
