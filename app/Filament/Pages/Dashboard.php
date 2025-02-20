<?php

namespace App\Filament\Pages;

use App\Jobs\RestartQueue;
use Filament\Pages\Dashboard;
use Filament\Actions;
use Filament\Notifications\Notification;

class CustomDashboard extends Dashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected function getActions(): array
    {
        return [
            Actions\Action::make('Reset Queue')
                ->action(function () {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new RestartQueue());
                })
                ->after(function () {
                    Notification::make()
                        ->success()
                        ->title('Queue reset')
                        ->body('The queue workers have been restarted and any pending jobs flushed. You may need to reset the status of your Playlist or EPG if it\'s stuck in a "Processing" state.')
                        ->duration(10000)
                        ->send();
                })
                ->size('sm')
                ->color('danger')
                ->requiresConfirmation()
                ->icon('heroicon-o-exclamation-triangle')
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->modalDescription('Resetting the queue will restart the queue workers and flush any pending jobs. You may need to reset the status of your Playlist or EPG if it\'s stuck in a "Processing" state.')
                ->modalSubmitActionLabel('Yes, reset now')
        ];
    }
}
