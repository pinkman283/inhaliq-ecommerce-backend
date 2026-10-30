<?php

namespace App\Http\Middleware;

use App\Models\Integration;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DynamicCorsOriginMiddleware
{
    /**
     * Cache key for dynamic CORS origins.
     */
    public const CACHE_KEY = 'headless_cors_allowed_origins';

    /**
     * Handle an incoming request and dynamically enforce database-managed CORS origins.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $allowedOrigins = $this->resolveAllowedOrigins();

        // Merge with existing config('cors.allowed_origins') so Laravel HandleCors knows them
        $currentConfig = config('cors.allowed_origins', []);
        $merged = array_values(array_unique(array_merge($currentConfig, $allowedOrigins)));
        config(['cors.allowed_origins' => $merged]);

        $origin = $request->header('Origin');

        // Handle CORS Preflight OPTIONS requests
        if ($request->isMethod('OPTIONS') && $origin) {
            if ($this->isOriginAllowed($origin, $merged)) {
                return response('', 204)
                    ->header('Access-Control-Allow-Origin', $origin)
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-API-Key, X-Idempotency-Key, Accept, Origin')
                    ->header('Access-Control-Allow-Credentials', 'true')
                    ->header('Access-Control-Max-Age', '86400');
            }

            return response()->json(['message' => 'Origin not allowed by CORS policy.'], 403);
        }

        $response = $next($request);

        // Append CORS headers to regular response if Origin matches
        if ($origin && $this->isOriginAllowed($origin, $merged)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * Resolve allowed origins from Cache or Database.
     */
    public function resolveAllowedOrigins(): array
    {
        return Cache::remember(self::CACHE_KEY, 86400, function () {
            try {
                $integration = Integration::where('provider', 'headless_api')
                    ->where('is_enabled', true)
                    ->first();

                if (!$integration) {
                    return [];
                }

                $rawOrigins = $integration->settings['allowed_origins'] ?? [];
                if (!is_array($rawOrigins)) {
                    $rawOrigins = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $rawOrigins)));
                }

                $validated = [];
                foreach ($rawOrigins as $origin) {
                    $clean = trim((string) $origin);
                    if ($this->isValidOriginFormat($clean)) {
                        $validated[] = rtrim($clean, '/');
                    }
                }

                return $validated;
            } catch (\Throwable $e) {
                Log::warning('Failed to load dynamic CORS origins from database: ' . $e->getMessage());
                return [];
            }
        });
    }

    /**
     * Check if a specific origin is permitted.
     */
    protected function isOriginAllowed(?string $origin, array $allowedOrigins): bool
    {
        if (empty($origin)) {
            return false;
        }

        $trimmedOrigin = rtrim($origin, '/');

        foreach ($allowedOrigins as $allowed) {
            if (rtrim($allowed, '/') === $trimmedOrigin) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate exact origin format (scheme + host + optional port, no wildcards).
     */
    public function isValidOriginFormat(string $origin): bool
    {
        if ($origin === '*' || empty($origin)) {
            return false;
        }

        // Must start with http:// or https:// and contain no paths or trailing slashes
        return (bool) preg_match('/^https?:\/\/[a-zA-Z0-9\-\._]+(:[0-9]+)?$/', rtrim($origin, '/'));
    }
}
