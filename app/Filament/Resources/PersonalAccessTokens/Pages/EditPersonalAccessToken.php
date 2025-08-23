<?php

namespace App\Filament\Resources\PersonalAccessTokens\Pages;

use App\Filament\Resources\PersonalAccessTokens\PersonalAccessTokenResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPersonalAccessToken extends EditRecord
{
    protected static string $resource = PersonalAccessTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
