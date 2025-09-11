<?php

namespace App\Listeners;

use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Spatie\Backup\Events\BackupHasFailed;
use Illuminate\Contracts\Queue\ShouldQueue;

class BackupFailed implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(BackupHasFailed $event): void
    {
        $user = User::whereIn('email', config('dev.admin_emails'))->first();
        if ($user) {
            $exception = $event->exception;
            $message = "Backup failed, error: \"{$exception->getMessage()}\"";
            FilamentNotification::make()
                ->danger()
                ->title("Backup failed")
                ->body($message)
                ->broadcast($user);
            FilamentNotification::make()
                ->danger()
                ->title("Backup failed")
                ->body($message)
                ->sendToDatabase($user);
        }
    }
}
