<?php

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ...

// Register schedule
Schedule::command('app:refresh-playlist')
    ->everyFiveMinutes();

Schedule::call(function () {
    $user = User::first();
    $now = now();
    Notification::make()
        ->success()
        ->title('Notification broadcast')
        ->body("Notification broadcast on: \"{$now->toDateTimeString()}\"")
        ->broadcast($user);
    Notification::make()
        ->success()
        ->title('Notification database')
        ->body("Notification database on: \"{$now->toDateTimeString()}\"")
        ->sendToDatabase($user);
})->everyMinute();
