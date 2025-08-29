<?php

namespace App\Http\Middleware;

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
                abort(403, 'Missing playlist unique ID');
            }
        }
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            abort(403, 'Invalid playlist unique ID');
        }

        // Store playlist id in cookies for later retrieval
        $request->attributes->set('playlist_uuid', $playlist->uuid);

        return;
    }

    protected function redirectTo($request): ?string
    {
        return '/'; // return to homepage if not authenticated
    }
}
