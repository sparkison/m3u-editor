<?php

namespace App\View\Components;

use Closure;
use Filament\Forms;
use Filament\Notifications\Notification;
use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo;

class ProfileComponent extends PersonalInfo
{
    protected function getEmailComponent(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('email')
            ->required()
            ->email()
            ->unique($this->userClass, ignorable: $this->user)
            ->disabled()
            ->hidden()
            ->label(__('filament-breezy::default.fields.email'));
    }
}
