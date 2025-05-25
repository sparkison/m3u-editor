<?php

namespace App\Filament\Resources\SeriesResource\Pages;

use App\Filament\Resources\SeriesResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSeries extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

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
