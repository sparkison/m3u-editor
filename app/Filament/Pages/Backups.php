<?php

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups as BaseBackups;

class Backups extends BaseBackups
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Backup & Restore';

    protected function getActions(): array
    {
        return [
            Action::make('Create Backup')
                ->button()
                ->label(__('filament-spatie-backup::backup.pages.backups.actions.create_backup'))
                ->action('openOptionModal'),
        ];
    }

    public function getHeading(): string | Htmlable
    {
        return 'Back & Restore';
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public function shouldDisplayStatusListRecords(): bool
    {
        return false;
    }
}
