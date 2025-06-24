<?php

namespace App\Http\Middleware;

use App\Http\Controllers\XtreamApiController;
use Closure;
use Illuminate\Http\Request;

class XtreamRootInterceptor
{
    /**
     * Handle an incoming request.
     * 
     * This middleware intercepts requests to the root path and checks if they
     * are Xtream API get_account_info requests. If so, it handles them directly.
     * Otherwise, it lets the request continue to Filament.
     */
    public function handle(Request $request, Closure $next)
    {
        // Only process GET requests to the root path
        if ($request->isMethod('GET') && $request->getPathInfo() === '/') {
            // Check if this is a get_account_info request
            if ($request->has(['username', 'password', 'action']) && 
                $request->input('action') === 'get_account_info') {
                
                // Handle as Xtream API request
                $controller = app(XtreamApiController::class);
                return $controller->handle($request);
            }
        }
        
        // Continue to next middleware (Filament)
        return $next($request);
    }
}
