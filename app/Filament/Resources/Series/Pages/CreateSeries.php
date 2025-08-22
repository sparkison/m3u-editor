<?php

namespace App\Filament\Resources\Series\Pages;

use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use App\Filament\Resources\Series\SeriesResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSeries extends CreateRecord
{
    use HasWizard;

    protected static string $resource = SeriesResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSteps(): array
    {
        return SeriesResource::getFormSteps();
    }
}
