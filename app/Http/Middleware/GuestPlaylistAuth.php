<?php

namespace App\Http\Middleware;

use App\Facades\PlaylistFacade;
use App\Filament\GuestPanel\Pages\GuestDashboard;
use App\Models\Playlist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class GuestPlaylistAuth extends Middleware
{
    /**
     * @param  array<string>  $guards
     */
    protected function authenticate($request, array $guards): void
    {
        $uuid = $request->route('uuid');
        if (!$uuid) {
            $uuid = $request->cookie('playlist_uuid');
            if (!$uuid) {
                abort(403, 'Missing playlist unique identifier');
            }
        }
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            abort(403, 'Invalid playlist unique identifier');
        }
        if (!$this->checkExistingAuth($uuid)) {
            // Only return 403 if not authenticated and not on the dashboard/landing page
            if (!in_array($request->route()->getName(), [
                'filament.playlist.home', // Base panel route
                GuestDashboard::getRouteName() // Redirected here from base route
            ])) {
                abort(403, 'Not authenticated');
            }
        }
        // Store playlist id in cookies for later retrieval
        $request->attributes->set('playlist_uuid', $playlist->uuid);

        return;
    }

    protected function redirectTo($request): ?string
    {
        return '/'; // return to homepage if not authenticated
    }

    private function checkExistingAuth($uuid): bool
    {
        $username = session('guest_auth_username');
        $password = session('guest_auth_password');
        if (!$username || !$password) {
            return false;
        }
        $result = PlaylistFacade::authenticate($username, $password);

        // If authenticated, check if the playlist UUID matches
        if ($result && $result[0]) {
            if ($result[0]->uuid !== $uuid) {
                return false;
            }
            return true;
        }

        return false;
    }
}
