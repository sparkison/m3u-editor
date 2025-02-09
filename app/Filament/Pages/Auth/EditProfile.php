<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\TextInput;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

class EditProfile extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->description('Update your profile information')
                    ->schema([
                        // Forms\Components\FileUpload::make('avatar_url')
                        //     ->disk('public')
                        //     ->avatar(),
                        // TextInput::make('username')->required()->maxLength(255),
                        $this->getNameFormComponent(),

                        // $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent()
                            ->helperText('Leave blank to keep the current password'),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
            ]);
    }
}
