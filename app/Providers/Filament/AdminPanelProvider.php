<?php

namespace App\Providers\Filament;

use Exception;
use App\Filament\Pages\Backups;
use App\Filament\Widgets\UpdateNoticeWidget;
use App\Filament\Auth\Login;
use App\Filament\Auth\EditProfile;
use App\Filament\Pages\CustomDashboard;
use App\Filament\Widgets\DiscordWidget;
use App\Filament\Widgets\DocumentsWidget;
use App\Filament\Widgets\DonateCrypto;
use App\Filament\Widgets\KoFiWidget;

//use App\Filament\Widgets\PayPalDonateWidget;
use App\Filament\Widgets\StatsOverview;
use App\Livewire\ProfileComponent;
use App\Settings\GeneralSettings;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Support\Enums\MaxWidth;
use Hydrat\TableLayoutToggle\TableLayoutTogglePlugin;
use Filament\Widgets\AccountWidget;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackupPlugin;
use Jeffgreco13\FilamentBreezy\BreezyCore;
use Saade\FilamentLaravelLog\FilamentLaravelLogPlugin;

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
        $settings = [
            'navigation_position' => 'left',
            'show_breadcrumbs' => true,
            'content_width' => MaxWidth::ScreenLarge,
            'show_logs' => false,
        ];
        try {
            $settings = [
                'navigation_position' => $userPreferences->navigation_position ?? $settings['navigation_position'],
                'show_breadcrumbs' => $userPreferences->show_breadcrumbs ?? $settings['show_breadcrumbs'],
                'content_width' => $userPreferences->content_width ?? $settings['content_width'],
                'show_logs' => $userPreferences->show_logs ?? $settings['show_logs'],
            ];
        } catch (Exception $e) {
            // Ignore
        }
        $adminPanel = $panel
            ->default()
            ->id('admin')
            ->path('/')
            ->login(Login::class)
            // ->profile(EditProfile::class, isSimple: false)
            ->brandName('m3u editor')
            ->brandLogo(fn() => view('filament.admin.logo'))
            ->favicon('/favicon.png')
            ->brandLogoHeight('2.5rem')
            ->databaseNotifications()
            ->databaseNotificationsPolling('10s')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                CustomDashboard::class
            ])
            ->breadcrumbs($settings['show_breadcrumbs'])
            ->widgets([
                UpdateNoticeWidget::class,
                AccountWidget::class,
                DocumentsWidget::class,
                DiscordWidget::class,
                // PayPalDonateWidget::class,
                KoFiWidget::class,
                // DonateCrypto::class,
                StatsOverview::class,
            ])
            ->plugins([
                FilamentSpatieLaravelBackupPlugin::make()
                    ->authorize(fn(): bool => in_array(auth()->user()->email, config('dev.admin_emails'), true))
                    ->usingPage(Backups::class),
                TableLayoutTogglePlugin::make(),
                BreezyCore::make()
                    ->myProfile(
                        slug: 'profile',
                        userMenuLabel: 'Profile',
                    )
                    ->enableTwoFactorAuthentication()
                    ->enableSanctumTokens()
                    ->myProfileComponents([
                        'personal_info' => ProfileComponent::class
                    ]),
                FilamentLaravelLogPlugin::make()
                    ->navigationGroup('Tools')
                    ->navigationSort(99)
                    ->logDirs([
                        config('app.log.dir'),
                    ])
                    ->authorize(fn(): bool => $settings['show_logs'] && in_array(auth()->user()->email, config('dev.admin_emails'), true))
            ])
            ->maxContentWidth($settings['content_width'])
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
                'epgs/*/epg.xml',
                '/logs*',
                // Xtream API endpoints
                'player_api.php*',
                'xmltv.php*',
                'live/*/*/*/*',
                'movie/*/*/*',
                'series/*/*/*/*',
            ]);
        if ($settings['navigation_position'] === 'top') {
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
