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
                    ->heading('Debugging')
                    ->description('Debug settings')
                    ->headerActions([
                        Forms\Components\Actions\Action::make('view_logs')
                            ->label('View Logs')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->iconPosition('after')
                            ->size('sm')
                            ->url('/logs')
                            ->openUrlInNewTab(true),
                        Forms\Components\Actions\Action::make('view_queue_manager')
                            ->label('Queue Manager')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->iconPosition('after')
                            ->size('sm')
                            ->url('/horizon')
                            ->openUrlInNewTab(true),
                    ])
                    ->schema([
                        Forms\Components\Toggle::make('show_logs')
                            ->label('Make log files viewable')
                            ->helperText('When enabled you can view the log files using the "View Logs" button. When disabled the logs endpoint will return a 403 (Unauthorized).'),
                        Forms\Components\Toggle::make('show_queue_manager')
                            ->label('Allow queue manager access')
                            ->helperText('When enabled you can access the queue manager using the "Queue Manager" button. When disabled the queue manager endpoint will return a 403 (Unauthorized).'),
                    ])
            ]);
    }
}
