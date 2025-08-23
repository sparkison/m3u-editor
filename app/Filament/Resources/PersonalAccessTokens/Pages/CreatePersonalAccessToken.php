<?php

namespace App\Filament\Resources\PersonalAccessTokens\Pages;

use App\Filament\Resources\PersonalAccessTokens\PersonalAccessTokenResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePersonalAccessToken extends CreateRecord
{
    protected static string $resource = PersonalAccessTokenResource::class;
}
