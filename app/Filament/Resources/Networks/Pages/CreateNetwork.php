<?php

namespace App\Filament\Resources\Networks\Pages;

use App\Filament\Resources\Networks\NetworkResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateNetwork extends CreateRecord
{
    protected static string $resource = NetworkResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
