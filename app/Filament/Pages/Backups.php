<?php

namespace App\Filament\Pages;

use App\Jobs\CreateBackup;
use App\Jobs\RestoreBackup;
use App\Models\Epg;
use App\Models\Playlist;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use ShuvroRoy\FilamentSpatieLaravelBackup\Models\BackupDestination;
use ShuvroRoy\FilamentSpatieLaravelBackup\Pages\Backups as BaseBackups;
use Filament\Forms;

class Backups extends BaseBackups
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Backup & Restore';

    protected ?string $subheading = 'NOTE: Restoring a backup will overwrite any existing data. Your manually uploaded EPG and Playlist files will NOT be restored. You will need to download the backup and manually re-upload where needed.';

    protected function getActions(): array
    {
        $availableBackups = BackupDestination::query()->get();
        return [
            Action::make('Restore Backup')
                ->form([
                    Forms\Components\Select::make('backup')
                        ->required()
                        ->label('Backup file')
                        ->helperText('Select the backup you would like to restore.')
                        ->options($availableBackups->pluck('path', 'path'))
                        ->searchable(),
                ])
                ->action(function (array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new RestoreBackup($data['backup']));
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title('Backup is being restored')
                        ->body('Backup is being restored in the background. Depending on the size of the backup, this could take a while.')
                        ->send();
                })
                ->requiresConfirmation()
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalIcon('heroicon-o-arrow-up-tray')
                ->modalDescription('NOTE: Only the database will be restored, which will overwrite any existing data with the backup data. Files will not be automatically restored, you will need to manually re-upload them where needed.')
                ->modalSubmitActionLabel('Restore now'),
            Action::make('Create Backup')
                ->form([
                    Forms\Components\Toggle::make('include_files')
                        ->label('Include Files')
                        ->helperText('When enabled, the backup will include your uploaded Playlist and EPG files.'),
                ])
                ->action(function (array $data): void {
                    app('Illuminate\Contracts\Bus\Dispatcher')
                        ->dispatch(new CreateBackup($data['include_files'] ?? false));
                })->after(function () {
                    Notification::make()
                        ->success()
                        ->title('Backup is being created')
                        ->body('Backup is being created in the background. Depending on the size of your database and files, this could take a while.')
                        ->send();
                })
                ->requiresConfirmation()
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->modalIcon('heroicon-o-arrow-down-tray')
                ->modalDescription('NOTE: When restoring a backup, only the database will be restored, files will not be automatically restored. You will need to manually re-upload them where needed.')
                ->modalSubmitActionLabel('Create now'),

//            Action::make('Create Backup')
//                ->button()
//                ->icon('heroicon-o-arrow-up-tray')
//                ->action('openOptionModal'),
        ];
    }

    public function getHeading(): string|Htmlable
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
