<?php

namespace App\Http\Middleware;

use Closure;
use App\Facades\PlaylistFacade;
use App\Filament\GuestPanel\Pages\GuestDashboard;
use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Auth\AuthenticationException;

class GuestPlaylistAuth extends Middleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$guards
     * @return mixed
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    public function handle($request, Closure $next, ...$guards)
    {
        $this->authenticate($request, $guards);

        return $next($request);
    }

    /**
     * Determine if the user is logged in to any of the given guards.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $guards
     * @return void
     *
     * @throws \Illuminate\Auth\AuthenticationException
     */
    protected function authenticate($request, array $guards)
    {
        $uuid = $request->route('uuid');
        if (!$uuid) {
            $uuid = $request->cookie('playlist_uuid');
            if (!$uuid) {
                throw new AuthenticationException(
                    'Unauthenticated.',
                    $guards,
                    route('filament.playlist.home') // Redirect to base panel route if no UUID found
                );
            }
        }
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            throw new AuthenticationException(
                'Invalid playlist unique identifier',
                $guards,
                route('filament.playlist.home', ['uuid' => $uuid]) // Redirect to base panel route if no valid playlist found
            );
        }
        if (!$this->checkExistingAuth($uuid)) {
            // Only return 403 if not authenticated and not on the dashboard/landing page
            if (!in_array($request->route()->getName(), [
                'filament.playlist.home', // Base panel route
                GuestDashboard::getRouteName() // Redirected here from base route
            ])) {
                throw new AuthenticationException(
                    'Not authenticated',
                    $guards,
                    route('filament.playlist.home', ['uuid' => $uuid]) // Redirect to base panel route if not authenticated
                );
            }
        }

        // Store playlist id in cookies for later retrieval
        $request->attributes->set('playlist_uuid', $playlist->uuid);
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
