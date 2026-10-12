<?php

namespace Tests\Feature;

use App\Http\Middleware\SlidingWindowThrottle;
use App\Models\Category;
use App\Models\Product;
use App\Services\Security\SlidingWindowRateLimiter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class RateLimitingSecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected SlidingWindowRateLimiter $limiter;
    protected SlidingWindowThrottle $throttleMiddleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limiter = new SlidingWindowRateLimiter();
        $this->throttleMiddleware = new SlidingWindowThrottle($this->limiter);

        // Ensure clean test isolation
        RateLimiter::clear('cust_login:*');
        Cache::flush();
    }

    // -------------------------------------------------------------
    // PART 1: SLIDING WINDOW LOG ALGORITHM TESTS
    // -------------------------------------------------------------

    /**
     * 1. Limiter allows attempts within threshold and blocks excess with Retry-After.
     */
    public function test_sliding_window_allows_attempts_within_limit_and_blocks_excess(): void
    {
        $key = 'test_basic_' . uniqid();
        $this->limiter->clear($key);

        for ($i = 1; $i <= 5; $i++) {
            $res = $this->limiter->attempt($key, 5, 60);
            $this->assertTrue($res['allowed'], "Attempt {$i} should be allowed.");
            $this->assertEquals(5 - $i, $res['remaining']);
        }

        // 6th attempt must be rejected
        $res6 = $this->limiter->attempt($key, 5, 60);
        $this->assertFalse($res6['allowed'], 'Attempt 6 should be rejected.');
        $this->assertEquals(0, $res6['remaining']);
        $this->assertGreaterThan(0, $res6['retry_after']);
    }

    /**
     * 2. Sliding window prevents boundary-burst attacks across window resets.
     */
    public function test_sliding_window_eliminates_boundary_burst_attack(): void
    {
        $key = 'test_boundary_' . uniqid();
        $this->limiter->clear($key);

        $baseTime = 1700000000.0;

        // 5 requests near end of 60s window (t = 50s)
        for ($i = 0; $i < 5; $i++) {
            $res = $this->limiter->attempt($key, 5, 60, $baseTime + 50.0 + ($i * 0.1));
            $this->assertTrue($res['allowed']);
        }

        // Request at t = 62s (across standard 60s minute boundary).
        // Sliding window must track the rolling 60s window and block this burst!
        $burstRes = $this->limiter->attempt($key, 5, 60, $baseTime + 62.0);
        $this->assertFalse($burstRes['allowed'], 'Sliding window allowed boundary burst.');
        $this->assertEquals(48, $burstRes['retry_after']);
    }

    /**
     * 3. Sliding window releases slots smoothly as individual timestamps roll off.
     */
    public function test_sliding_window_releases_slots_smoothly_as_timestamps_expire(): void
    {
        $key = 'test_rolling_' . uniqid();
        $this->limiter->clear($key);

        $baseTime = 1700000000.0;

        // 3 requests at t = 10s, 2 requests at t = 40s
        $this->limiter->attempt($key, 5, 60, $baseTime + 10.0);
        $this->limiter->attempt($key, 5, 60, $baseTime + 11.0);
        $this->limiter->attempt($key, 5, 60, $baseTime + 12.0);
        $this->limiter->attempt($key, 5, 60, $baseTime + 40.0);
        $this->limiter->attempt($key, 5, 60, $baseTime + 41.0);

        // At t = 50s, window is full
        $resAt50 = $this->limiter->attempt($key, 5, 60, $baseTime + 50.0);
        $this->assertFalse($resAt50['allowed']);

        // Advance to t = 71.0s (request at t=10 has expired: 71 - 60 = 11s)
        $resAt71 = $this->limiter->attempt($key, 5, 60, $baseTime + 71.0);
        $this->assertTrue($resAt71['allowed'], 'Slot should be released once the oldest request rolls out.');
    }

    /**
     * 4. Clear and reset functions immediately unblock rate-limited keys.
     */
    public function test_sliding_window_clear_and_reset(): void
    {
        $key = 'test_clear_' . uniqid();
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->attempt($key, 5, 60);
        }

        $this->assertTrue($this->limiter->tooManyAttempts($key, 5, 60));
        $this->limiter->clear($key);
        $this->assertFalse($this->limiter->tooManyAttempts($key, 5, 60));
    }

    // -------------------------------------------------------------
    // PART 2: HTTP MIDDLEWARE & ENDPOINT RATE LIMITING TESTS
    // -------------------------------------------------------------

    /**
     * 5. Customer login endpoint enforces 5 attempts/min with HTTP 429 and Retry-After header.
     */
    public function test_repeated_login_from_same_ip_triggers_http_429_with_retry_after(): void
    {
        $ip = '198.51.100.1';
        $email = 'shopper_rate_' . Str::random(6) . '@example.com';

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/auth/login', [
                    'email' => $email,
                    'password' => 'wrong_password_' . $i,
                ]);

            $this->assertNotEquals(429, $response->getStatusCode(), "Attempt {$i} was prematurely rate limited.");
        }

        // 6th attempt MUST return HTTP 429
        $blocked = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'wrong_password_6',
            ]);

        $blocked->assertStatus(429);
        $this->assertTrue($blocked->headers->has('Retry-After'), 'Missing Retry-After response header.');
        $this->assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $blocked->json('retry_after'));
    }

    /**
     * 6. Different client IPs maintain strictly isolated rate limit buckets.
     */
    public function test_different_client_ips_have_isolated_rate_limit_buckets(): void
    {
        $userA = 'user_ip_a_' . Str::random(6) . '@example.com';
        $userB = 'user_ip_b_' . Str::random(6) . '@example.com';
        $ipA = '198.51.100.20';
        $ipB = '198.51.100.21';

        // Exhaust IP A
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ipA])
                ->postJson('/api/auth/login', ['email' => $userA, 'password' => 'wrong']);
        }

        $resA = $this->withServerVariables(['REMOTE_ADDR' => $ipA])
            ->postJson('/api/auth/login', ['email' => $userA, 'password' => 'wrong']);
        $resA->assertStatus(429);

        // IP B must remain unaffected and allowed
        $resB = $this->withServerVariables(['REMOTE_ADDR' => $ipB])
            ->postJson('/api/auth/login', ['email' => $userB, 'password' => 'wrong']);
        $this->assertNotEquals(429, $resB->getStatusCode(), 'IP B was incorrectly throttled by IP A exhaustion.');
    }

    /**
     * 7. Spoofed X-Forwarded-For header fails to bypass rate limiting.
     */
    public function test_spoofed_x_forwarded_for_fails_to_bypass_rate_limiting(): void
    {
        $realIp = '198.51.100.30';
        $email = 'spoof_check_' . Str::random(6) . '@example.com';

        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $realIp])
                ->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong']);
        }

        // Attempt 6 with spoofed X-Forwarded-For
        $blocked = $this->withServerVariables(['REMOTE_ADDR' => $realIp])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.88'])
            ->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong']);

        $blocked->assertStatus(429);
    }

    /**
     * 8. Distributed attack across multiple IPs triggers Tier 2 account-level rate limit.
     */
    public function test_distributed_attack_on_single_account_triggers_tier_2_limit(): void
    {
        $targetEmail = 'target_ceo_' . Str::random(6) . '@example.com';

        // 1 attempt from 10 distinct IPs (account limit is 10/min)
        for ($i = 1; $i <= 10; $i++) {
            $ip = "198.51.100.1{$i}";
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/auth/login', ['email' => $targetEmail, 'password' => 'bot_guess_' . $i]);
        }

        // 11th attempt from brand new IP should be blocked by account tier
        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson('/api/auth/login', ['email' => $targetEmail, 'password' => 'bot_guess_11']);

        $response->assertStatus(429);
    }

    /**
     * 9. Shared IP permits different legitimate accounts to log in independently.
     */
    public function test_shared_ip_permits_independent_user_logins(): void
    {
        $sharedIp = '198.51.100.50';

        // User 1 attempts 2 times
        for ($i = 1; $i <= 2; $i++) {
            $res1 = $this->withServerVariables(['REMOTE_ADDR' => $sharedIp])
                ->postJson('/api/auth/login', ['email' => 'student1_' . Str::random(6) . '@school.edu', 'password' => 'wrong']);
            $this->assertNotEquals(429, $res1->getStatusCode());
        }

        // User 2 attempts 2 times from same IP
        for ($i = 1; $i <= 2; $i++) {
            $res2 = $this->withServerVariables(['REMOTE_ADDR' => $sharedIp])
                ->postJson('/api/auth/login', ['email' => 'student2_' . Str::random(6) . '@school.edu', 'password' => 'wrong']);
            $this->assertNotEquals(429, $res2->getStatusCode(), 'User 2 blocked by shared IP prematurely.');
        }
    }

    /**
     * 10. Coupon validation endpoint enforces 10 req/min sliding window probe limit.
     */
    public function test_coupon_validation_sliding_window_limit(): void
    {
        $ip = '10.200.3.1';

        for ($i = 1; $i <= 10; $i++) {
            $res = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/coupons/validate', [
                    'code' => 'PROBE_' . $i . '_' . Str::random(4),
                    'cart_total' => 100,
                ]);

            $this->assertNotEquals(429, $res->getStatusCode(), "Probe attempt {$i} prematurely blocked.");
        }

        // 11th probe from same IP triggers sliding window throttle
        $blocked = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/coupons/validate', [
                'code' => 'PROBE_EXCESS_' . Str::random(4),
                'cart_total' => 100,
            ]);

        $blocked->assertStatus(429);
    }

    /**
     * 11. Order checkout enforces 10 req/min sliding window limit for guest orders.
     */
    public function test_order_checkout_sliding_window_limit(): void
    {
        $ip = '10.200.4.1';
        $guestEmail = 'checkout_bot_' . Str::random(6) . '@example.com';

        for ($i = 1; $i <= 10; $i++) {
            $res = $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/orders', [
                    'customer_email' => $guestEmail,
                    'customer_name' => 'Spam Bot',
                    'shipping_method' => 'inside_dhaka',
                    'payment_method' => 'cash_on_delivery',
                    'shipping_address' => [
                        'address_line1' => 'Fake St',
                        'city' => 'Dhaka',
                    ],
                    'items' => [],
                ]);

            $this->assertNotEquals(429, $res->getStatusCode(), "Checkout attempt {$i} prematurely throttled.");
        }

        // 11th checkout request MUST be throttled
        $blocked = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/orders', [
                'customer_email' => $guestEmail,
                'customer_name' => 'Spam Bot',
                'items' => [],
            ]);

        $blocked->assertStatus(429);
    }
}
