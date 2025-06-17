<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class MediaFlowProxyMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if MediaFlow proxy is enabled
        if (!config('mediaflow.enabled', true)) {
            abort(503, 'MediaFlow proxy is disabled');
        }

        // Rate limiting
        if (config('mediaflow.rate_limiting.enabled', true)) {
            $this->checkRateLimit($request);
        }

        // Validate required parameters
        $this->validateRequest($request);

        // Log request if enabled
        if (config('mediaflow.logging.enabled', true)) {
            $this->logRequest($request);
        }

        $response = $next($request);

        // Log response if enabled
        if (config('mediaflow.logging.enabled', true)) {
            $this->logResponse($request, $response);
        }

        return $response;
    }

    /**
     * Check rate limiting
     */
    private function checkRateLimit(Request $request): void
    {
        $key = 'mediaflow_proxy:' . $request->ip();
        
        // Per minute rate limit
        $perMinuteLimit = config('mediaflow.rate_limiting.requests_per_minute', 120);
        if (RateLimiter::tooManyAttempts($key . ':minute', $perMinuteLimit)) {
            abort(429, 'Rate limit exceeded: too many requests per minute');
        }
        RateLimiter::hit($key . ':minute', 60);

        // Per hour rate limit
        $perHourLimit = config('mediaflow.rate_limiting.requests_per_hour', 1000);
        if (RateLimiter::tooManyAttempts($key . ':hour', $perHourLimit)) {
            abort(429, 'Rate limit exceeded: too many requests per hour');
        }
        RateLimiter::hit($key . ':hour', 3600);
    }

    /**
     * Validate request parameters
     */
    private function validateRequest(Request $request): void
    {
        // For most endpoints, require destination parameter
        if (in_array($request->route()->getName(), [
            'mediaflow.proxy.hls.manifest',
            'mediaflow.proxy.stream',
            'mediaflow.proxy.stream.file'
        ])) {
            if (!$request->has('d') || empty($request->get('d'))) {
                abort(400, 'Destination parameter (d) is required');
            }

            // Validate URL format
            $destination = $request->get('d');
            if (!filter_var($destination, FILTER_VALIDATE_URL)) {
                abort(400, 'Invalid destination URL format');
            }

            // Check for potentially dangerous schemes
            $scheme = parse_url($destination, PHP_URL_SCHEME);
            if (!in_array($scheme, ['http', 'https'])) {
                abort(400, 'Only HTTP and HTTPS schemes are allowed');
            }
        }
    }

    /**
     * Log incoming request
     */
    private function logRequest(Request $request): void
    {
        $logLevel = config('mediaflow.logging.level', 'info');
        $includeHeaders = config('mediaflow.logging.include_headers', false);

        $logData = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'destination' => $request->get('d'),
        ];

        if ($includeHeaders) {
            $logData['headers'] = $request->headers->all();
        }

        Log::channel('ffmpeg')->{$logLevel}('MediaFlow Proxy Request', $logData);
    }

    /**
     * Log response
     */
    private function logResponse(Request $request, Response $response): void
    {
        $logLevel = config('mediaflow.logging.level', 'info');

        $logData = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'status' => $response->getStatusCode(),
            'content_type' => $response->headers->get('Content-Type'),
        ];

        Log::channel('ffmpeg')->{$logLevel}('MediaFlow Proxy Response', $logData);
    }
}
