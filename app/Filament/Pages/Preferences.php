<?php

namespace App\Filament\Pages;

use App\Settings\GeneralSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;
use Filament\Support\Enums\MaxWidth;
use Forms\Components;

class Preferences extends SettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = GeneralSettings::class;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->heading('App appearance preferences')
                    ->description('NOTE: You may need to reload the page to see these changes.')
                    ->schema([
                        Forms\Components\Select::make('navigation_position')
                            ->label('Navigation position')
                            ->helperText('Choose the position of primary navigation')
                            ->options([
                                'left' => 'Left',
                                'top' => 'Top',
                            ]),
                        Forms\Components\Toggle::make('show_jobs_navigation')
                            ->label('Show jobs navigation')
                            ->helperText('View jobs and job status for background tasks'),
                        Forms\Components\Toggle::make('show_breadcrumbs')
                            ->label('Show breadcrumbs')
                            ->helperText('Show breadcrumbs under the page titles'),
                        Forms\Components\Select::make('content_width')
                            ->label('Max width of the page content')
                            ->options([
                                MaxWidth::ScreenMedium->value => 'Medium',
                                MaxWidth::ScreenLarge->value => 'Large',
                                MaxWidth::ScreenExtraLarge->value => 'XL',
                                MaxWidth::ScreenTwoExtraLarge->value => '2XL',
                                MaxWidth::Full->value => 'Full',
                            ]),
                    ]),
                Forms\Components\Section::make()
                    ->heading('Processing')
                    ->description('Processing preferences and settings.')
                    ->schema([
                        Forms\Components\TextInput::make('playlist_agent_string')
                            ->label('Playlist user agent string')
                            ->required()
                            ->helperText('The default user agent string used to fetch your playlists.'),
                        Forms\Components\TextInput::make('epg_agent_string')
                            ->label('EPG user agent string')
                            ->required()
                            ->helperText('The default user agent string used to fetch your EPGs.'),
                    ])
            ]);
    }
}
