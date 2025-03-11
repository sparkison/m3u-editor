<?php

namespace App\Listeners;

use App\Models\User;
use Filament\Notifications\Notification;
use Spatie\Backup\Events\BackupWasSuccessful;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class BackupCompleted
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
    public function handle(BackupWasSuccessful $event): void
    {
        $user = User::where('email', 'admin@test.com')->first();
        if ($user) {
            $backupDestination = $event->backupDestination;
            $backupName = $backupDestination->backups()->last()->path();
            $message = "Backup completed successfully. Backup name: \"$backupName\"";

            Notification::make()
                ->success()
                ->title("Backup completed successfully")
                ->body($message)
                ->broadcast($user);
            Notification::make()
                ->success()
                ->title("Backup completed successfully")
                ->body($message)
                ->sendToDatabase($user);
        }

    }
}
