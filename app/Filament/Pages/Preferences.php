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
                    ->description('Debug and development settings.')
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
                            ->helperText('When enabled you can view the log files using the "View Logs" button. When disabled, the logs endpoint will return a 403 (Unauthorized).'),
                        Forms\Components\Toggle::make('show_queue_manager')
                            ->label('Allow queue manager access')
                            ->helperText('When enabled you can access the queue manager using the "Queue Manager" button. When disabled, the queue manager endpoint will return a 403 (Unauthorized).'),
                        Forms\Components\Toggle::make('show_proxy_debug')
                            ->label('Show proxy (ffmpeg) debug in cli')
                            ->helperText('When enabled, you can view the ffmpeg debug output in the console. When disabled, the debug output will be hidden. This setting only affects the ffmpeg CLI output, not the logs.'),
                    ]),

                Forms\Components\Section::make()
                    ->heading('API')
                    ->description('Use the API to make calls directly to the app.')
                    ->headerActions([
                        Forms\Components\Actions\Action::make('manage_api_keys')
                            ->label('Manage API Tokens')
                            ->color('gray')
                            ->icon('heroicon-s-key')
                            ->iconPosition('before')
                            ->size('sm')
                            ->url('/profile'),
                        Forms\Components\Actions\Action::make('view_api_docs')
                            ->label('API Docs')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->iconPosition('after')
                            ->size('sm')
                            ->url('/docs/api')
                            ->openUrlInNewTab(true),
                    ])
                    ->schema([
                        Forms\Components\Toggle::make('show_api_docs')
                            ->label('Allow access to API docs')
                            ->helperText('When enabled you can access the API documentation using the "API Docs" button. When disabled, the docs endpoint will return a 403 (Unauthorized). NOTE: The API will respond regardless of this setting. You do not need to enable it to use the API.'),
                    ])
            ]);
    }
}
