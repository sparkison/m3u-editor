<?php

namespace App\Http\Middleware;

use App\Models\Playlist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class GuestPlaylistAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $uuid = $request->route('uuid');
        if (!$uuid) {
            return response()->json(['error' => 'Missing playlist UUID'], 401);
        }
        $playlist = Playlist::where('uuid', $uuid)->first();
        if (!$playlist) {
            return response()->json(['error' => 'Invalid playlist UUID'], 401);
        }
        // Optionally, you can add more checks (e.g., playlist enabled, not expired, etc.)
        // Attach playlist to request for downstream use
        $request->attributes->set('playlist', $playlist);
        return $next($request);
    }
}
