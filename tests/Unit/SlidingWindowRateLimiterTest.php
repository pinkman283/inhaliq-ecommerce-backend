<?php

namespace Tests\Unit;

use App\Services\Security\SlidingWindowRateLimiter;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SlidingWindowRateLimiterTest extends TestCase
{
    protected SlidingWindowRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->limiter = new SlidingWindowRateLimiter();
    }

    public function test_allows_first_attempt_and_decrements_remaining(): void
    {
        $key = 'test_ip_1';
        $now = 1000.0;

        $result = $this->limiter->attempt($key, 5, 60, $now);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(5, $result['limit']);
        $this->assertEquals(4, $result['remaining']);
        $this->assertEquals(1, $result['current']);
        $this->assertEquals(0, $result['retry_after']);
    }

    public function test_blocks_requests_when_limit_is_exceeded_within_window(): void
    {
        $key = 'test_ip_2';
        $now = 2000.0;

        // Perform 3 allowed attempts for limit of 3
        for ($i = 0; $i < 3; $i++) {
            $res = $this->limiter->attempt($key, 3, 60, $now + $i);
            $this->assertTrue($res['allowed']);
        }

        // 4th attempt should be blocked
        $blocked = $this->limiter->attempt($key, 3, 60, $now + 3);

        $this->assertFalse($blocked['allowed']);
        $this->assertEquals(0, $blocked['remaining']);
        $this->assertEquals(3, $blocked['current']);
        $this->assertGreaterThan(0, $blocked['retry_after']);
    }

    public function test_old_requests_slide_out_of_window_automatically(): void
    {
        $key = 'test_ip_3';
        $t0 = 5000.0;

        // 2 requests at t0
        $this->limiter->attempt($key, 2, 60, $t0);
        $this->limiter->attempt($key, 2, 60, $t0 + 1);

        // At t0 + 10, limit is reached
        $this->assertTrue($this->limiter->tooManyAttempts($key, 2, 60, $t0 + 10));

        // Advance time to t0 + 62 (past the 60 second window)
        $tFuture = $t0 + 62.0;
        $this->assertFalse($this->limiter->tooManyAttempts($key, 2, 60, $tFuture));

        // Attempt should now succeed
        $res = $this->limiter->attempt($key, 2, 60, $tFuture);
        $this->assertTrue($res['allowed']);
    }

    public function test_clear_resets_rate_limit_state(): void
    {
        $key = 'test_ip_4';
        $now = 8000.0;

        $this->limiter->attempt($key, 1, 60, $now);
        $this->assertTrue($this->limiter->tooManyAttempts($key, 1, 60, $now));

        $this->limiter->clear($key);

        $this->assertFalse($this->limiter->tooManyAttempts($key, 1, 60, $now));
    }
}
