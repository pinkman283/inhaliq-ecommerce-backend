<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UpstashRedisIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'redis_admin_test@aether.com'],
            [
                'name' => 'Redis Integration Admin',
                'password' => bcrypt('Password123!'),
                'role' => 'admin',
                'status' => 'active',
            ]
        );
        $this->admin->status = 'active';
        $this->admin->role = 'admin';
        $this->admin->save();

        Cache::flush();
    }

    /**
     * 1. GET /api/system/redis-config rejects unauthorized requests with 401.
     */
    public function test_system_redis_config_rejects_unauthorized_requests(): void
    {
        $response = $this->getJson('/api/system/redis-config');

        $response->assertStatus(401);
        $response->assertJson([
            'error' => 'unauthorized_redis_config_access',
        ]);
    }

    /**
     * 2. GET /api/system/redis-config succeeds with valid server-to-server X-API-Key.
     */
    public function test_system_redis_config_accessible_via_headless_api_key(): void
    {
        Integration::updateOrCreate(
            ['provider' => 'headless_api'],
            [
                'name' => 'Headless API Configuration',
                'category' => 'api',
                'is_enabled' => true,
                'credentials' => [
                    'primary_api_key' => 'inh_live_test_key_redis_7788',
                ],
            ]
        );

        Integration::updateOrCreate(
            ['provider' => 'upstash_redis'],
            [
                'name' => 'Upstash Redis Cache',
                'category' => 'system',
                'is_enabled' => true,
                'credentials' => [
                    'url' => 'https://valid-db.upstash.io',
                    'token' => 'SECRET_REST_TOKEN_UPSTASH_9999',
                ],
                'settings' => [
                    'default_ttl' => 7200,
                    'cache_navigation' => true,
                    'cache_catalog' => true,
                ],
            ]
        );

        $response = $this->withHeaders([
            'X-API-Key' => 'inh_live_test_key_redis_7788',
        ])->getJson('/api/system/redis-config');

        $response->assertStatus(200);
        $response->assertJson([
            'is_enabled' => true,
            'configured' => true,
            'url' => 'https://valid-db.upstash.io',
            'token' => 'SECRET_REST_TOKEN_UPSTASH_9999',
            'default_ttl' => 7200,
            'cache_navigation' => true,
            'cache_catalog' => true,
        ]);
    }

    /**
     * 3. GET /api/system/redis-config returns disabled status when integration is disabled.
     */
    public function test_system_redis_config_returns_disabled_when_integration_is_off(): void
    {
        Integration::updateOrCreate(
            ['provider' => 'headless_api'],
            [
                'name' => 'Headless API Configuration',
                'category' => 'api',
                'is_enabled' => true,
                'credentials' => [
                    'primary_api_key' => 'inh_live_test_key_redis_7788',
                ],
            ]
        );

        Integration::updateOrCreate(
            ['provider' => 'upstash_redis'],
            [
                'name' => 'Upstash Redis Cache',
                'category' => 'system',
                'is_enabled' => false,
                'credentials' => [
                    'url' => 'https://valid-db.upstash.io',
                    'token' => 'SECRET_TOKEN',
                ],
            ]
        );

        $response = $this->withHeaders([
            'X-API-Key' => 'inh_live_test_key_redis_7788',
        ])->getJson('/api/system/redis-config');

        $response->assertStatus(200);
        $response->assertJson([
            'is_enabled' => false,
            'url' => null,
            'token' => null,
        ]);
    }

    /**
     * 4. Admin API index and show endpoints return masked credentials (never plaintext token).
     */
    public function test_admin_api_masks_upstash_redis_token(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        Integration::updateOrCreate(
            ['provider' => 'upstash_redis'],
            [
                'name' => 'Upstash Redis Cache',
                'category' => 'system',
                'is_enabled' => true,
                'credentials' => [
                    'url' => 'https://my-upstash-db.upstash.io',
                    'token' => 'AX3OHg9TOKEN1234567890ABCDEF',
                ],
            ]
        );

        $response = $this->getJson('/api/admin/integrations/upstash_redis');

        $response->assertStatus(200);
        $creds = $response->json('integration.credentials');

        // URL should be visible
        $this->assertEquals('https://my-upstash-db.upstash.io', $creds['url']);

        // Token must be masked with bullets
        $this->assertStringContainsString('••••••••', $creds['token']);
        $this->assertStringNotContainsString('TOKEN1234567890', $creds['token']);
    }

    /**
     * 5. Updating credentials stores encrypted at rest and preserves existing secret on bullet update.
     */
    public function test_updating_credentials_encrypts_at_rest_and_preserves_bullets(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $originalToken = 'REAL_UPSTASH_SECRET_TOKEN_998877';

        $integration = Integration::updateOrCreate(
            ['provider' => 'upstash_redis'],
            [
                'name' => 'Upstash Redis Cache',
                'category' => 'system',
                'is_enabled' => true,
                'credentials' => [
                    'url' => 'https://my-upstash-db.upstash.io',
                    'token' => $originalToken,
                ],
                'settings' => [
                    'default_ttl' => 3600,
                ],
            ]
        );

        // Verify encrypted at rest in raw database
        $rawRow = \Illuminate\Support\Facades\DB::table('integrations')
            ->where('provider', 'upstash_redis')
            ->first();
        $this->assertNotEmpty($rawRow->credentials);
        $this->assertStringNotContainsString($originalToken, $rawRow->credentials);

        // Send update with masked bullets in token (simulating form save without re-typing secret)
        $updateRes = $this->putJson('/api/admin/integrations/upstash_redis', [
            'credentials' => [
                'url' => 'https://updated-upstash-db.upstash.io',
                'token' => 'AX3O••••••••CDEF', // masked
            ],
            'settings' => [
                'default_ttl' => 7200,
            ],
        ]);

        $updateRes->assertStatus(200);

        // Refresh model and verify original secret was preserved
        $integration->refresh();
        $this->assertEquals('https://updated-upstash-db.upstash.io', $integration->credentials['url']);
        $this->assertEquals($originalToken, $integration->credentials['token']);
        $this->assertEquals(7200, $integration->settings['default_ttl']);
    }

    /**
     * 6. Test connection validates missing fields and invalid URL format.
     */
    public function test_test_connection_validates_required_fields_and_format(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        Integration::updateOrCreate(
            ['provider' => 'upstash_redis'],
            [
                'name' => 'Upstash Redis Cache',
                'category' => 'system',
                'credentials' => [],
            ]
        );

        // Missing creds
        $res1 = $this->postJson('/api/admin/integrations/upstash_redis/test', [
            'credentials' => [
                'url' => '',
                'token' => '',
            ],
        ]);
        $res1->assertStatus(200);
        $res1->assertJson([
            'success' => false,
            'test_status' => 'failed',
        ]);
        $this->assertStringContainsString('required', $res1->json('message'));

        // Invalid URL format
        $res2 = $this->postJson('/api/admin/integrations/upstash_redis/test', [
            'credentials' => [
                'url' => 'invalid-url-domain',
                'token' => 'some-token',
            ],
        ]);
        $res2->assertStatus(200);
        $res2->assertJson([
            'success' => false,
            'test_status' => 'failed',
        ]);
        $this->assertStringContainsString('Must start with https://', $res2->json('message'));
    }

    /**
     * 7. Test connection handles successful PONG and 401 Unauthorized appropriately.
     */
    public function test_test_connection_handles_pong_and_401(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        Integration::updateOrCreate(
            ['provider' => 'upstash_redis'],
            [
                'name' => 'Upstash Redis Cache',
                'category' => 'system',
                'credentials' => [],
            ]
        );

        // Mock successful PONG response
        Http::fake([
            'https://test-success.upstash.io/ping' => Http::response(['result' => 'PONG'], 200),
            'https://test-unauth.upstash.io/ping' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $resSuccess = $this->postJson('/api/admin/integrations/upstash_redis/test', [
            'credentials' => [
                'url' => 'https://test-success.upstash.io',
                'token' => 'valid-mock-token',
            ],
        ]);
        $resSuccess->assertStatus(200);
        $resSuccess->assertJson([
            'success' => true,
            'test_status' => 'connected',
        ]);
        $this->assertStringContainsString('PONG', $resSuccess->json('message'));

        // Mock 401 Unauthorized
        $resUnauth = $this->postJson('/api/admin/integrations/upstash_redis/test', [
            'credentials' => [
                'url' => 'https://test-unauth.upstash.io',
                'token' => 'bad-token',
            ],
        ]);
        $resUnauth->assertStatus(200);
        $resUnauth->assertJson([
            'success' => false,
            'test_status' => 'failed',
        ]);
        $this->assertStringContainsString('authentication failed', $resUnauth->json('message'));
    }

    /**
     * 8. Toggling upstash_redis integration flips is_enabled and clears cache.
     */
    public function test_toggling_upstash_redis_updates_state(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $integration = Integration::updateOrCreate(
            ['provider' => 'upstash_redis'],
            [
                'name' => 'Upstash Redis Cache',
                'category' => 'system',
                'is_enabled' => false,
            ]
        );

        $toggleRes = $this->postJson('/api/admin/integrations/upstash_redis/toggle');
        $toggleRes->assertStatus(200);
        $toggleRes->assertJson([
            'is_enabled' => true,
        ]);

        $integration->refresh();
        $this->assertTrue($integration->is_enabled);
    }
}
