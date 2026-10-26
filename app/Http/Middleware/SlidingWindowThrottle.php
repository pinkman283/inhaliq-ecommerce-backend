<?php

namespace App\Http\Middleware;

use App\Services\Security\SlidingWindowRateLimiter;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SlidingWindowThrottle
{
    public function __construct(
        protected SlidingWindowRateLimiter $limiter
    ) {}

    /**
     * Handle an incoming request with Sliding Window Rate Limiting.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $limiterName Named rule or inline parameter (e.g. 'auth-customer-login' or '10,60')
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $limiterName = 'default'): Response
    {
        $tiers = $this->resolveTiers($request, $limiterName);

        $minRemaining = PHP_INT_MAX;
        $minLimit = PHP_INT_MAX;
        $maxResetAt = 0;

        foreach ($tiers as $tier) {
            $key = $tier['key'];
            $maxAttempts = $tier['limit'];
            $windowSeconds = $tier['window'] ?? 60;
            $message = $tier['message'] ?? 'Too many requests. Please slow down.';

            $result = $this->limiter->attempt($key, $maxAttempts, $windowSeconds);

            if (!$result['allowed']) {
                $retryAfter = $result['retry_after'];
                $resetAt = $result['reset_at'];

                $headers = [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $maxAttempts,
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => (string) $resetAt,
                ];

                return new JsonResponse([
                    'message' => $message,
                    'retry_after' => $retryAfter,
                ], 429, $headers);
            }

            if ($result['remaining'] < $minRemaining) {
                $minRemaining = $result['remaining'];
                $minLimit = $maxAttempts;
            }
            if ($result['reset_at'] > $maxResetAt) {
                $maxResetAt = $result['reset_at'];
            }
        }

        $response = $next($request);

        if ($minLimit !== PHP_INT_MAX) {
            $response->headers->set('X-RateLimit-Limit', (string) $minLimit);
            $response->headers->set('X-RateLimit-Remaining', (string) max(0, $minRemaining));
            $response->headers->set('X-RateLimit-Reset', (string) $maxResetAt);
        }

        return $response;
    }

    /**
     * Resolve rate limit tiers based on named profile or inline parameters.
     *
     * @param Request $request
     * @param string $limiterName
     * @return array<int, array{key: string, limit: int, window: int, message: string}>
     */
    protected function resolveTiers(Request $request, string $limiterName): array
    {
        $ip = $request->ip() ?: '127.0.0.1';
        $user = $request->user();

        switch ($limiterName) {
            // 1. Customer Authentication (3-Tier Sliding Defense)
            case 'auth-customer-login':
                $email = Str::transliterate(Str::lower(trim((string) $request->input('email', ''))));
                return [
                    [
                        'key' => 'cust_login:' . $email . '|' . $ip,
                        'limit' => 5,
                        'window' => 60,
                        'message' => 'Too many login attempts for this account from your IP. Please slow down.',
                    ],
                    [
                        'key' => 'cust_login_account:' . $email,
                        'limit' => 10,
                        'window' => 60,
                        'message' => 'Too many login attempts for this account. Please wait before trying again.',
                    ],
                    [
                        'key' => 'cust_login_ip:' . $ip,
                        'limit' => 20,
                        'window' => 60,
                        'message' => 'Too many login attempts originating from this network.',
                    ],
                ];

            // 2. Admin Authentication (Strict 3-Tier Sliding Defense)
            case 'auth-admin-login':
                $email = Str::transliterate(Str::lower(trim((string) $request->input('email', ''))));
                return [
                    [
                        'key' => 'admin_login:' . $email . '|' . $ip,
                        'limit' => 5,
                        'window' => 60,
                        'message' => 'Too many administrative login attempts. Access temporarily restricted.',
                    ],
                    [
                        'key' => 'admin_login_account:' . $email,
                        'limit' => 8,
                        'window' => 60,
                        'message' => 'Too many attempts for this administrative account. Access temporarily paused.',
                    ],
                    [
                        'key' => 'admin_login_ip:' . $ip,
                        'limit' => 15,
                        'window' => 60,
                        'message' => 'Too many administrative login attempts from this network.',
                    ],
                ];

            // 3. Customer Registration (IP Bucket + Composite)
            case 'auth-register':
                $email = Str::transliterate(Str::lower(trim((string) $request->input('email', ''))));
                return [
                    [
                        'key' => 'register_ip:' . $ip,
                        'limit' => 5,
                        'window' => 60,
                        'message' => 'Too many account registrations originating from this network.',
                    ],
                    [
                        'key' => 'register_target:' . $email . '|' . $ip,
                        'limit' => 3,
                        'window' => 60,
                        'message' => 'Too many registration attempts for this email address.',
                    ],
                ];

            // 4. Order Creation / Checkout
            case 'order-checkout':
                if ($user) {
                    return [
                        [
                            'key' => 'order_user:' . $user->id . '|' . $ip,
                            'limit' => 10,
                            'window' => 60,
                            'message' => 'Order creation rate limit reached. Please wait before placing another order.',
                        ],
                    ];
                }
                $email = Str::transliterate(Str::lower(trim((string) $request->input('customer_email', 'guest'))));
                return [
                    [
                        'key' => 'order_guest_ip:' . $ip,
                        'limit' => 15,
                        'window' => 60,
                        'message' => 'Too many checkout requests originating from this network. Please wait.',
                    ],
                    [
                        'key' => 'order_guest:' . $email . '|' . $ip,
                        'limit' => 8,
                        'window' => 60,
                        'message' => 'Checkout rate limit reached for this account. Please wait before placing another order.',
                    ],
                ];

            // 4b. Public Order Tracking (Anti-scraping defense)
            case 'order-tracking':
                return [
                    [
                        'key' => 'order_track_ip:' . $ip,
                        'limit' => 30,
                        'window' => 60,
                        'message' => 'Too many order tracking requests. Please slow down.',
                    ],
                ];

            // 4c. Public Cart Validation (DoS protection)
            case 'cart-validation':
                $identifier = $user ? ('user:' . $user->id) : ('ip:' . $ip);
                return [
                    [
                        'key' => 'cart_val:' . $identifier,
                        'limit' => 45,
                        'window' => 60,
                        'message' => 'Too many cart verification requests. Please wait a moment.',
                    ],
                ];

            // 5. Coupon Validation (Anti-Probe + Target Code Lock)
            case 'coupon-validation':
                $identifier = $user ? ('user:' . $user->id) : ('ip:' . $ip);
                $code = Str::upper(trim((string) $request->input('code', '')));
                return [
                    [
                        'key' => 'coupon_probe:' . $identifier,
                        'limit' => 10,
                        'window' => 60,
                        'message' => 'Too many coupon validation requests. Please wait a moment.',
                    ],
                    [
                        'key' => 'coupon_target:' . $identifier . '|' . $code,
                        'limit' => 5,
                        'window' => 60,
                        'message' => 'Too many validation attempts for this coupon code.',
                    ],
                ];

            // 6. Customer Review Submission
            case 'customer-reviews':
                $identifier = $user ? ('user:' . $user->id) : ('ip:' . $ip);
                return [
                    [
                        'key' => 'review:' . $identifier,
                        'limit' => 5,
                        'window' => 60,
                        'message' => 'Review submission rate limit reached. Please wait before submitting another review.',
                    ],
                ];

            // 7. Storefront Lead Capture
            case 'leads-capture':
                $email = Str::transliterate(Str::lower(trim((string) $request->input('email', ''))));
                return [
                    [
                        'key' => 'lead:' . $email . '|' . $ip,
                        'limit' => 15,
                        'window' => 60,
                        'message' => 'Too many lead submissions.',
                    ],
                ];

            // 8. Sensitive Admin Action
            case 'sensitive-admin-action':
                $userId = $user ? $user->id : 'anon';
                return [
                    [
                        'key' => 'admin_action:' . $userId . '|' . $ip,
                        'limit' => 30,
                        'window' => 60,
                        'message' => 'Administrative request threshold reached.',
                    ],
                ];

            // Fallback / Inline Custom format (e.g. '10,60')
            default:
                if (str_contains($limiterName, ',')) {
                    [$limit, $window] = array_map('intval', explode(',', $limiterName));
                    $identifier = $user ? ('user:' . $user->id) : ('ip:' . $ip);
                    return [
                        [
                            'key' => 'custom:' . $identifier,
                            'limit' => max(1, $limit),
                            'window' => max(1, $window),
                            'message' => 'Too many requests. Please slow down.',
                        ],
                    ];
                }

                return [
                    [
                        'key' => 'global:' . $ip,
                        'limit' => 60,
                        'window' => 60,
                        'message' => 'Too many requests. Please slow down.',
                    ],
                ];
        }
    }
}
