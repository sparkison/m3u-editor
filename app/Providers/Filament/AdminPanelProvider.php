<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Auth\EditProfile;
use App\Settings\GeneralSettings;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use \Croustibat\FilamentJobsMonitor\FilamentJobsMonitorPlugin;
use Filament\Support\Enums\MaxWidth;
use Hydrat\TableLayoutToggle\TableLayoutTogglePlugin;

class AdminPanelProvider extends PanelProvider
{
    protected static ?string $navigationIcon = 'heroicon-o-tachometer';
    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-tachometer';
    }

    public function panel(Panel $panel): Panel
    {
        $userPreferences = app(GeneralSettings::class);
        $adminPanel = $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->brandLogo(fn() => view('filament.admin.logo'))
            ->brandLogoHeight('2rem')
            ->databaseNotifications()
            ->databaseNotificationsPolling('10s')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->breadcrumbs($preferences->show_breadcrumbs ?? true)
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // Widgets\AccountWidget::class,
                // Widgets\FilamentInfoWidget::class,
            ])
            ->plugins([
                FilamentJobsMonitorPlugin::make()
                    ->enableNavigation(app()->environment('local')), // local only for testing...
                TableLayoutTogglePlugin::make(),
            ])
            ->maxContentWidth($userPreferences->content_width ?? MaxWidth::ScreenLarge)
            // ->simplePageMaxContentWidth(MaxWidth::Small) // Login, sign in, etc.
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->spa()
            ->spaUrlExceptions(fn(): array => [
                '*/playlist.m3u',
                '*/epg.xml',
                'epgs/*/epg.xml'
            ]);

        if ($userPreferences->navigation_position === 'top') {
            $adminPanel->topNavigation();
        } else {
            $adminPanel->sidebarCollapsibleOnDesktop();
        }

        return $adminPanel;
    }

    public function register(): void
    {
        parent::register();
        FilamentView::registerRenderHook('panels::body.end', fn() => Blade::render("@vite('resources/js/app.js')"));
    }
}
