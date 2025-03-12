<?php

namespace App\Livewire;

use Livewire\Component;
use Filament\Forms;
use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo;

class ProfileComponent extends PersonalInfo
{
    public array $only = ['name'];
    
    protected function getProfileFormSchema(): array
    {
        $groupFields = Forms\Components\Group::make([
            $this->getNameComponent(),
            //$this->getEmailComponent(),
        ])->columnSpan(2);

        return ($this->hasAvatars)
            ? [filament('filament-breezy')->getAvatarUploadComponent(), $groupFields]
            : [$groupFields];
    }
}
