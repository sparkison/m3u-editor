<?php

namespace App\Filament\Resources\Networks\Pages;

use App\Filament\Resources\Networks\NetworkResource;
use App\Services\NetworkScheduleService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditNetwork extends EditRecord
{
    protected static string $resource = NetworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateSchedule')
                ->label('Generate Schedule')
                ->icon('heroicon-o-calendar')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Generate Schedule')
                ->modalDescription('This will generate a 7-day programme schedule for this network. Existing future programmes will be replaced.')
                ->action(function () {
                    $service = app(NetworkScheduleService::class);
                    $service->generateSchedule($this->record);

                    Notification::make()
                        ->success()
                        ->title('Schedule Generated')
                        ->body("Generated programme schedule for {$this->record->name}")
                        ->send();

                    $this->refreshFormData(['schedule_generated_at']);
                }),

            DeleteAction::make(),
        ];
    }
}
