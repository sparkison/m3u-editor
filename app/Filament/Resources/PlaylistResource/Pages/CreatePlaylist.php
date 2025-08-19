<?php

namespace App\Filament\Resources\PlaylistResource\Pages;

use App\Filament\Resources\PlaylistResource;
use App\Models\PlaylistAuth;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePlaylist extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = PlaylistResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getSteps(): array
    {
        return PlaylistResource::getFormSteps();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Remove auth-related fields from playlist creation data
        // These will be handled after the playlist is created
        unset($data['create_auth'], $data['auth_name'], $data['auth_username'], $data['auth_password']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $data = $this->form->getState();

        // Check if user wants to create auth and has provided necessary data
        if (
            isset($data['create_auth']) &&
            $data['create_auth'] &&
            !empty($data['auth_username']) &&
            !empty($data['auth_password'])
        ) {
            // Create the PlaylistAuth
            $auth = PlaylistAuth::create([
                'user_id' => Auth::id(),
                'name' => $data['auth_name'] ?: 'Auth for ' . $this->record->name,
                'username' => $data['auth_username'],
                'password' => $data['auth_password'],
                'enabled' => true,
            ]);

            // Assign the auth to the created playlist
            $auth->assignTo($this->record);
        }
    }
}
