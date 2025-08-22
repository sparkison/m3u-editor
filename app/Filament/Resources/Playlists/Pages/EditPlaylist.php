<?php

namespace App\Filament\Resources\Playlists\Pages;

use Filament\Actions\ViewAction;
use App\Enums\Status;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Playlists\Widgets\ImportProgress;
use App\Models\Playlist;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Redis;

class EditPlaylist extends EditRecord
{
    //use EditRecord\Concerns\HasWizard;

    protected static string $resource = PlaylistResource::class;

    public function hasSkippableSteps(): bool
    {
        return true;
    }

    public function getVisibleHeaderWidgets(): array
    {
        return [
            ImportProgress::class
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->label('View Playlist')
                ->icon('heroicon-m-eye')
                ->color('gray')
                ->action(function () {
                    $this->redirect($this->getRecord()->getUrl('view'));
                }),
            ...PlaylistResource::getHeaderActions(),
        ];
    }

    protected function getSteps(): array
    {
        return PlaylistResource::getFormSteps();
    }
}
