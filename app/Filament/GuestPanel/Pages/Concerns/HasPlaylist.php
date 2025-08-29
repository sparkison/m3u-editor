<?php

namespace App\Filament\GuestPanel\Pages\Concerns;

use App\Facades\PlaylistFacade;

trait HasPlaylist
{
    public static $uuid = null;

    public function mount(...$params): void
    {
        // Load playlist info
        static::$uuid = static::getCurrentUuid();
        parent::mount(...$params);
    }

    protected static function getCurrentUuid(): ?string
    {
        return request()->route('uuid') ?? request()->attributes->get('playlist_uuid');
    }

    public function isGuestAuthenticated(): bool
    {
        return $this->isAuthenticated();
    }

    protected function isAuthenticated(): bool
    {
        $username = session('guest_auth_username');
        $password = session('guest_auth_password');
        if (!$username || !$password) {
            return false;
        }
        $result = PlaylistFacade::authenticate($username, $password);

        // If authenticated, check if the playlist UUID matches
        if ($result && $result[0]) {
            if ($result[0]->uuid !== static::getCurrentUuid()) {
                return false;
            }
            return true;
        }

        return false;
    }
}
