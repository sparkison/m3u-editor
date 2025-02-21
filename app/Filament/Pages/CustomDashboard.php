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
                        ->body('The queue workers have been restarted and any pending jobs flushed. You may need to manually sync any Playlists or EPGs that were in progress.')
                        ->duration(10000)
                        ->send();
                })
                ->size('sm')
                ->color('danger')
                ->requiresConfirmation()
                ->icon('heroicon-o-exclamation-triangle')
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->modalDescription('Resetting the queue will restart the queue workers and flush any pending jobs. Any syncs or background processes will be stopped and removed. Only perform this action if you are having sync issues.')
                ->modalSubmitActionLabel('I understand, reset now')
        ];
    }
}
