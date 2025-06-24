<?php

namespace App\Http\Middleware;

use App\Http\Controllers\XtreamApiController;
use Closure;
use Illuminate\Http\Request;

class HandleXtreamAccountInfo
{
    /**
     * Handle an incoming request.
     *
     * This middleware runs before Filament routing and intercepts 
     * get_account_info requests at the root path.
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if this is a GET request to root with get_account_info parameters
        if ($request->isMethod('GET') && 
            $request->getPathInfo() === '/' &&
            $request->has(['username', 'password', 'action']) && 
            $request->input('action') === 'get_account_info') {
            
            // Handle as Xtream API request
            $controller = app(XtreamApiController::class);
            return $controller->handle($request);
        }
        
        // Continue to next middleware/route
        return $next($request);
    }
}
