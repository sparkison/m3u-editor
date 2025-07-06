<?php

namespace App\Exceptions;

use Exception;

class AllPlaylistsExhaustedException extends Exception
{
    /**
     * Report the exception.
     *
     * @return bool|null
     */
    public function report()
    {
        // You can add custom logging here if needed,
        // but the controller will likely log it as well.
        return false; // Returning false prevents default logging if you handle it elsewhere.
    }

    /**
     * Render the exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 429);
        }

        // For web requests, you could return a view here,
        // but we'll handle it in the controller for more flexibility.
        // Or, you can use abort() which Laravel's handler will turn into a nice error page.
        return response($this->getMessage(), 429);
    }
}
