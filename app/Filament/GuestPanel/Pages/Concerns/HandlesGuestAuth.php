<?php

namespace App\Filament\GuestPanel\Pages\Concerns;

use App\Facades\PlaylistFacade;

trait HandlesGuestAuth
{
    protected function isAuthenticated(): bool
    {
        $username = session('guest_auth_username');
        $password = session('guest_auth_password');
        if (!$username || !$password) {
            return false;
        }
        $result = PlaylistFacade::authenticate($username, $password);
        return $result && $result[0];
    }

    protected function tryAuthenticate(string $username, string $password): bool
    {
        $result = PlaylistFacade::authenticate($username, $password);
        if ($result && $result[0]) {
            session(['guest_auth_username' => $username, 'guest_auth_password' => $password]);
            return true;
        }
        return false;
    }

    protected function logoutGuest(): void
    {
        session()->forget(['guest_auth_username', 'guest_auth_password']);
    }

    protected function getGuestAuthUser(): ?array
    {
        $username = session('guest_auth_username');
        $password = session('guest_auth_password');
        if (!$username || !$password) {
            return null;
        }
        $result = PlaylistFacade::authenticate($username, $password);
        return $result && $result[0] ? $result : null;
    }
}
