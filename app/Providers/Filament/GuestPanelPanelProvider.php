<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class GuestPanelPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('guest')
            ->path('guest/{uuid}')
            ->middleware([
                'web',
                \App\Http\Middleware\GuestPlaylistAuth::class,
            ])
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/GuestPanel/Resources'), for: 'App\Filament\GuestPanel\Resources')
            ->discoverPages(in: app_path('Filament/GuestPanel/Pages'), for: 'App\Filament\GuestPanel\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/GuestPanel/Widgets'), for: 'App\Filament\GuestPanel\Widgets')
            ->widgets([
                FilamentInfoWidget::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
