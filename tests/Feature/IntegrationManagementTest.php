<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use App\Services\FraudCheckerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegrationManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin_test_integrations@aether.com'],
            [
                'name' => 'Integration Admin',
                'password' => bcrypt('AdminPassword123!'),
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
     * 1. Public tracking endpoint returns GTM & Meta Pixel info without leaking secrets.
     */
    public function test_public_tracking_endpoint_returns_safe_data_without_secrets(): void
    {
        Integration::updateOrCreate(
            ['provider' => 'meta_pixel'],
            [
                'name' => 'Meta Pixel & CAPI',
                'category' => 'analytics',
                'is_enabled' => true,
                'credentials' => [
                    'pixel_id' => '1806216353748383',
                    'access_token' => 'SECRET_CAPI_ACCESS_TOKEN_XYZ999',
                ],
                'settings' => [
                    'auto_pageview' => true,
                    'track_add_to_cart' => true,
                    'track_purchase' => true,
                ],
            ]
        );

        Integration::updateOrCreate(
            ['provider' => 'google_tag_manager'],
            [
                'name' => 'Google Tag Manager',
                'category' => 'analytics',
                'is_enabled' => true,
                'credentials' => [
                    'container_id' => 'GTM-TEST123',
                ],
                'settings' => [
                    'data_layer_name' => 'dataLayer',
                ],
            ]
        );

        $response = $this->getJson('/api/integrations/tracking');

        $response->assertStatus(200);
        $response->assertJson([
            'gtm' => [
                'enabled' => true,
                'container_id' => 'GTM-TEST123',
                'data_layer_name' => 'dataLayer',
            ],
            'meta_pixel' => [
                'enabled' => true,
                'pixel_id' => '1806216353748383',
                'settings' => [
                    'auto_pageview' => true,
                    'track_add_to_cart' => true,
                    'track_purchase' => true,
                ],
            ],
        ]);

        // Assert strictly that secrets are NEVER in response
        $rawContent = $response->getContent();
        $this->assertStringNotContainsString('SECRET_CAPI_ACCESS_TOKEN_XYZ999', $rawContent);
        $this->assertStringNotContainsString('access_token', $rawContent);
    }

    /**
     * 2. Admin read endpoints return masked credentials instead of plaintext secrets.
     */
    public function test_admin_api_returns_masked_credentials(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $integration = Integration::updateOrCreate(
            ['provider' => 'fraud_checker'],
            [
                'name' => 'Courier Fraud & Return Checker',
                'category' => 'fraud',
                'is_enabled' => true,
                'credentials' => [
                    'api_key' => 'frd_super_secret_key_1234567890',
                ],
                'settings' => [
                    'auto_flag_threshold_percent' => 35,
                ],
            ]
        );

        $response = $this->getJson("/api/admin/integrations/{$integration->provider}");

        $response->assertStatus(200);
        $data = $response->json('integration');

        $this->assertNotEmpty($data['credentials']['api_key']);
        $this->assertStringContainsString('•', $data['credentials']['api_key']);
        $this->assertStringNotContainsString('frd_super_secret_key_1234567890', $response->getContent());
    }

    /**
     * 3. Saving with masked credentials bullet characters does not overwrite real secret.
     */
    public function test_saving_with_bullet_mask_preserves_underlying_secret(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $originalSecret = 'stdf_very_secret_real_key_9988';
        $integration = Integration::updateOrCreate(
            ['provider' => 'steadfast'],
            [
                'name' => 'Steadfast Courier API',
                'category' => 'courier',
                'is_enabled' => true,
                'credentials' => [
                    'api_key' => 'stdf_live_api_8829104',
                    'secret_key' => $originalSecret,
                ],
            ]
        );

        // Simulate admin submitting form where secret_key is masked
        $response = $this->putJson("/api/admin/integrations/{$integration->provider}", [
            'is_enabled' => true,
            'is_test_mode' => false,
            'credentials' => [
                'api_key' => 'stdf_live_api_8829104',
                'secret_key' => 'stdf••••••••9988',
            ],
            'settings' => [
                'default_pickup_address' => 'Updated Banani Hub',
            ],
        ]);

        $response->assertStatus(200);

        // Fresh fetch from database
        $fresh = Integration::where('provider', 'steadfast')->first();
        $this->assertEquals($originalSecret, $fresh->credentials['secret_key']);
        $this->assertEquals('Updated Banani Hub', $fresh->settings['default_pickup_address']);
    }

    /**
     * 4. GTM & Meta Pixel validation in test endpoint.
     */
    public function test_gtm_and_pixel_validation(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        // GTM valid format
        Integration::where('provider', 'google_tag_manager')->update([
            'credentials' => ['container_id' => 'GTM-ABCD123'],
        ]);
        $resGtm = $this->postJson('/api/admin/integrations/google_tag_manager/test');
        $resGtm->assertStatus(200);
        $this->assertTrue($resGtm->json('success'));

        // GTM invalid format
        Integration::where('provider', 'google_tag_manager')->update([
            'credentials' => ['container_id' => 'INVALID_ID'],
        ]);
        $resGtmBad = $this->postJson('/api/admin/integrations/google_tag_manager/test');
        $resGtmBad->assertStatus(200);
        $this->assertFalse($resGtmBad->json('success'));

        // Meta Pixel non-numeric
        Integration::where('provider', 'meta_pixel')->update([
            'credentials' => ['pixel_id' => 'not-a-number'],
        ]);
        $resMetaBad = $this->postJson('/api/admin/integrations/meta_pixel/test');
        $resMetaBad->assertStatus(200);
        $this->assertFalse($resMetaBad->json('success'));

        // Meta Pixel valid numeric
        Integration::where('provider', 'meta_pixel')->update([
            'credentials' => ['pixel_id' => '1806216353748383'],
        ]);
        $resMetaGood = $this->postJson('/api/admin/integrations/meta_pixel/test');
        $resMetaGood->assertStatus(200);
        $this->assertTrue($resMetaGood->json('success'));
    }

    /**
     * 5. Headless API key rotation and CORS origin validation.
     */
    public function test_headless_api_key_rotation_and_cors_validation(): void
    {
        Sanctum::actingAs($this->admin, ['admin:access']);

        $initialKey = 'inh_live_initial_key_111111111111';
        Integration::updateOrCreate(
            ['provider' => 'headless_api'],
            [
                'name' => 'Headless API & CORS Configuration',
                'category' => 'api',
                'is_enabled' => true,
                'credentials' => [
                    'primary_api_key' => $initialKey,
                    'secondary_api_key' => null,
                ],
                'settings' => [
                    'allowed_origins' => ['http://localhost:3000', 'https://inhaliq.com'],
                ],
            ]
        );

        // Rotate key
        $rotateRes = $this->postJson('/api/admin/integrations/headless_api/rotate-key');
        $rotateRes->assertStatus(200);
        $this->assertNotEmpty($rotateRes->json('primary_api_key'));
        $this->assertNotEquals($initialKey, $rotateRes->json('primary_api_key'));
        $this->assertEquals($initialKey, $rotateRes->json('secondary_api_key'));

        // Test CORS origin validation (disallowing wildcards)
        Integration::where('provider', 'headless_api')->update([
            'settings' => [
                'allowed_origins' => ['*'],
            ],
        ]);
        $wildcardRes = $this->postJson('/api/admin/integrations/headless_api/test');
        $this->assertFalse($wildcardRes->json('success'));
        $this->assertStringContainsString('Wildcard', $wildcardRes->json('message'));

        // Valid origins
        Integration::where('provider', 'headless_api')->update([
            'settings' => [
                'allowed_origins' => ['https://store.inhaliq.com:3000'],
            ],
        ]);
        $validCorsRes = $this->postJson('/api/admin/integrations/headless_api/test');
        $this->assertTrue($validCorsRes->json('success'));
    }

    /**
     * 6. Fraud checker service performs safe connection test and non-blocking risk check.
     */
    public function test_fraud_checker_service_execution(): void
    {
        $service = app(FraudCheckerService::class);

        // Safe test connection
        $testResult = $service->testConnection(['api_key' => 'test_key'], []);
        $this->assertTrue($testResult['success']);
        $this->assertGreaterThan(0, $testResult['latency_ms']);

        // Check standard customer
        $resultSafe = $service->checkCustomerRisk('01711000000');
        $this->assertEquals('verified_safe', $resultSafe['status']);
        $this->assertFalse($resultSafe['is_high_risk']);
        $this->assertEquals('allow', $resultSafe['policy']);

        // Check customer with simulated abuse phone
        $resultAbuse = $service->checkCustomerRisk('01711000099');
        $this->assertEquals('flagged_risk', $resultAbuse['status']);
        $this->assertTrue($resultAbuse['is_high_risk']);
        $this->assertEquals('require_verification', $resultAbuse['policy']);
    }
}
