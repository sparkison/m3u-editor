<?php

namespace App\Filament\GuestPanel\Pages;

use App\Facades\PlaylistFacade;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use App\Filament\GuestPanel\Pages\Concerns\HandlesGuestAuth;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Forms;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Illuminate\Support\Facades\Session;

class GuestDashboard extends Page implements HasSchemas
{
    use HandlesGuestAuth;
    use InteractsWithSchemas;

    protected string $view = 'filament.guest-panel.pages.guest-dashboard';
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-play';
    protected static ?string $navigationLabel = 'Playlist';
    protected static ?string $slug = 'guest';

    public ?array $data = [];

    public $username = '';
    public $password = '';
    public $authError = '';

    public function mount(): void
    {
        $this->form->fill();
        $this->username = session('guest_auth_username', '');
        $this->password = session('guest_auth_password', '');
    }

    public function login(): void
    {
        if ($this->tryAuthenticate($this->username, $this->password)) {
            $this->authError = '';
        } else {
            $this->authError = 'Invalid credentials.';
        }
    }

    public function logout(): void
    {
        $this->logoutGuest();
        $this->username = '';
        $this->password = '';
        $this->authError = '';
    }

    public function getTitle(): string|Htmlable
    {
        $playlist = PlaylistFacade::resolvePlaylistByUuid($this->getCurrentUuid());
        return $playlist->name ?? 'Playlist';
    }

    protected static function getCurrentUuid(): ?string
    {
        return request()->route('uuid') ?? request()->attributes->get('playlist_uuid');
    }

    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        $tenant = null
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();
        return route(static::getRouteName($panel), $parameters, $isAbsolute);
    }

    public function isGuestAuthenticated(): bool
    {
        return $this->isAuthenticated();
    }

    public function getGuestAuthUserData(): ?array
    {
        return $this->getGuestAuthUser();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('username')
                    ->required(),
                Forms\Components\TextInput::make('password')
                    ->revealable()
                    ->required(),
            ])->statePath('data');
    }

    public function create(): void
    {
        dd($this->form->getState());
    }
}
