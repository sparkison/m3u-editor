<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard;
use Filament\Actions;
use Filament\Notifications\Notification as FilamentNotification;

class CustomDashboard extends Dashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-tv';

    protected function getActions(): array
    {
        return [
            //
        ];
    }
}
