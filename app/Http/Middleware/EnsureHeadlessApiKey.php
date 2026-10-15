<?php

namespace App\Http\Middleware;

use App\Models\Integration;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHeadlessApiKey
{
    /**
     * Handle incoming request and verify Headless API Key when auth is enforced.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $integration = Integration::where('provider', 'headless_api')->first();

        // If integration does not exist, is disabled, or api_auth_enabled is not active, allow request through
        if (!$integration || !$integration->is_enabled || empty($integration->settings['api_auth_enabled'])) {
            return $next($request);
        }

        $incomingKey = $request->header('X-API-Key');
        if (empty($incomingKey)) {
            $bearer = $request->bearerToken();
            if ($bearer && str_starts_with($bearer, 'inh_')) {
                $incomingKey = $bearer;
            }
        }

        if (empty($incomingKey)) {
            return response()->json([
                'message' => 'Missing Headless API Key. Include X-API-Key header with your request.',
                'error' => 'unauthenticated_headless_api',
            ], 401);
        }

        $credentials = $integration->credentials ?? [];
        $primary = $credentials['primary_api_key'] ?? null;
        $secondary = $credentials['secondary_api_key'] ?? null;

        $isValid = false;
        if (!empty($primary) && hash_equals($primary, $incomingKey)) {
            $isValid = true;
        } elseif (!empty($secondary) && hash_equals($secondary, $incomingKey)) {
            $isValid = true;
        }

        if (!$isValid) {
            return response()->json([
                'message' => 'Invalid or expired Headless API Key.',
                'error' => 'invalid_api_key',
            ], 401);
        }

        return $next($request);
    }
}
