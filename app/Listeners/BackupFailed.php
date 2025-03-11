<?php

namespace App\Listeners;

use App\Models\User;
use Filament\Notifications\Notification;
use Spatie\Backup\Events\BackupHasFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class BackupFailed
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(BackupHasFailed $event): void
    {
        $user = User::where('email', 'admin@test.com')->first();
        if ($user) {
            $exception = $event->exception;
            $message = "Backup failed, error: \"{$exception->getMessage()}\"";
            Notification::make()
                ->danger()
                ->title("Backup failed")
                ->body($message)
                ->broadcast($user);
            Notification::make()
                ->danger()
                ->title("Backup failed")
                ->body($message)
                ->sendToDatabase($user);
        }
    }
}
