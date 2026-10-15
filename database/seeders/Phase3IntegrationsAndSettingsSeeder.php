<?php

namespace Database\Seeders;

use App\Models\Integration;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class Phase3IntegrationsAndSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Integrations
        $integrations = [
            // Payments
            [
                'provider' => 'bkash',
                'name' => 'bKash Merchant Checkout',
                'category' => 'payment',
                'description' => 'Direct tokenized bKash PGW API for seamless MFS checkout in Bangladesh.',
                'icon' => 'bkash',
                'is_enabled' => true,
                'is_test_mode' => true,
                'credentials' => [
                    'app_key' => 'bks_sandbox_app_99182',
                    'app_secret' => 'bks_sec_9912093849182',
                    'username' => '01812345678',
                    'password' => 'Pass@1234',
                ],
                'settings' => [
                    'sandbox' => true,
                    'currency' => 'BDT',
                    'intent' => 'sale',
                ],
                'last_tested_at' => now()->subMinutes(12),
                'test_status' => 'connected',
                'test_message' => 'Token generation successful (182ms ping)',
            ],
            [
                'provider' => 'nagad',
                'name' => 'Nagad PGW',
                'category' => 'payment',
                'description' => 'Nagad digital wallet checkout with automated IPN callback processing.',
                'icon' => 'nagad',
                'is_enabled' => true,
                'is_test_mode' => true,
                'credentials' => [
                    'merchant_id' => 'NGD_001920',
                    'public_key' => 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCB',
                    'private_key' => 'MIICXAIBAAKCAQEA0r176v...',
                ],
                'settings' => [
                    'sandbox' => true,
                ],
                'last_tested_at' => now()->subHours(1),
                'test_status' => 'connected',
                'test_message' => 'PGW Handshake OK',
            ],
            [
                'provider' => 'sslcommerz',
                'name' => 'SSLCommerz Enterprise',
                'category' => 'payment',
                'description' => 'Unified gateway supporting Visa, MasterCard, Amex, bKash, Nagad, Rocket and Net Banking.',
                'icon' => 'sslcommerz',
                'is_enabled' => true,
                'is_test_mode' => true,
                'credentials' => [
                    'store_id' => 'aethersoundlive',
                    'store_passwd' => 'aether_ssl_passwd_9921',
                ],
                'settings' => [
                    'sandbox' => true,
                ],
                'last_tested_at' => now()->subHours(2),
                'test_status' => 'connected',
                'test_message' => 'Session validation active',
            ],
            [
                'provider' => 'stripe',
                'name' => 'Stripe Payments',
                'category' => 'payment',
                'description' => 'Global card checkout, Apple Pay, Google Pay and multi-currency billing.',
                'icon' => 'stripe',
                'is_enabled' => true,
                'is_test_mode' => true,
                'credentials' => [
                    'publishable_key' => 'pk_test_51Mz99201847291',
                    'secret_key' => 'sk_test_51Mz99201847291secret',
                    'webhook_secret' => 'whsec_98124820194812',
                ],
                'settings' => [
                    'capture_method' => 'automatic',
                ],
                'last_tested_at' => now()->subMinutes(45),
                'test_status' => 'connected',
                'test_message' => 'Stripe API v2024-04-10 ping: 200 OK (84ms)',
            ],
            [
                'provider' => 'cod',
                'name' => 'Cash on Delivery (COD)',
                'category' => 'payment',
                'description' => 'Collect payment in cash or courier POS terminal upon physical parcel delivery.',
                'icon' => 'cod',
                'is_enabled' => true,
                'is_test_mode' => false,
                'credentials' => [],
                'settings' => [
                    'max_cod_amount' => 50000,
                    'extra_fee' => 0,
                ],
                'last_tested_at' => now(),
                'test_status' => 'connected',
                'test_message' => 'COD rules active',
            ],

            // Couriers
            [
                'provider' => 'steadfast',
                'name' => 'Steadfast Courier API',
                'category' => 'courier',
                'description' => 'Automated consignment booking, bulk parcel tracking and cash return reconciliation.',
                'icon' => 'steadfast',
                'is_enabled' => true,
                'is_test_mode' => true,
                'credentials' => [
                    'api_key' => 'stdf_live_api_8829104',
                    'secret_key' => 'stdf_sec_49182049182',
                ],
                'settings' => [
                    'default_pickup_address' => 'Aether Hub, Plot 14, Banani, Dhaka',
                    'auto_booking_on_confirm' => true,
                ],
                'last_tested_at' => now()->subMinutes(20),
                'test_status' => 'connected',
                'test_message' => 'Steadfast API v1 connected. Available Balance: BDT 4,850',
            ],
            [
                'provider' => 'pathao',
                'name' => 'Pathao Logistics API',
                'category' => 'courier',
                'description' => 'On-demand city delivery and nationwide courier service.',
                'icon' => 'pathao',
                'is_enabled' => true,
                'is_test_mode' => true,
                'credentials' => [
                    'client_id' => 'pathao_cid_99201',
                    'client_secret' => 'pathao_csec_881923',
                    'username' => 'merchant@aether.test',
                    'password' => 'SecretPass@2026',
                ],
                'settings' => [
                    'store_id' => '84920',
                    'item_type' => 'Parcel',
                ],
                'last_tested_at' => now()->subHours(3),
                'test_status' => 'connected',
                'test_message' => 'Pathao OAuth token active',
            ],
            [
                'provider' => 'redx',
                'name' => 'RedX Delivery Network',
                'category' => 'courier',
                'description' => 'Door-to-door parcel delivery with OTP verification and live tracking.',
                'icon' => 'redx',
                'is_enabled' => false,
                'is_test_mode' => true,
                'credentials' => [
                    'api_token' => 'redx_tok_772910481',
                ],
                'settings' => [
                    'pickup_store_id' => 'RDX_STR_091',
                ],
                'last_tested_at' => null,
                'test_status' => 'untested',
                'test_message' => null,
            ],

            // SMS Gateways
            [
                'provider' => 'bulksms_bd',
                'name' => 'BulkSMS BD Gateway',
                'category' => 'sms',
                'description' => 'High-speed SMS broadcasting with mask & non-mask alphanumeric sender ID.',
                'icon' => 'sms',
                'is_enabled' => true,
                'is_test_mode' => false,
                'credentials' => [
                    'api_key' => 'sms_bd_api_991823746192',
                    'sender_id' => 'AETHER',
                ],
                'settings' => [
                    'masking' => true,
                ],
                'last_tested_at' => now()->subMinutes(5),
                'test_status' => 'connected',
                'test_message' => 'SMS Gateway Ping OK. Remaining Balance: 1,420 SMS',
            ],
            [
                'provider' => 'greenweb',
                'name' => 'Greenweb SMS',
                'category' => 'sms',
                'description' => 'Bangladesh MNO direct routing for instant OTP & order notifications.',
                'icon' => 'greenweb',
                'is_enabled' => false,
                'is_test_mode' => true,
                'credentials' => [
                    'token' => 'gw_token_8819204',
                ],
                'settings' => [],
                'last_tested_at' => null,
                'test_status' => 'untested',
                'test_message' => null,
            ],
            [
                'provider' => 'twilio',
                'name' => 'Twilio International SMS',
                'category' => 'sms',
                'description' => 'Global cellular messaging and two-factor authentication via Twilio API.',
                'icon' => 'twilio',
                'is_enabled' => false,
                'is_test_mode' => true,
                'credentials' => [
                    'account_sid' => 'AC48192048192048192',
                    'auth_token' => 'tw_auth_88291048102',
                    'from_number' => '+15550192834',
                ],
                'settings' => [],
                'last_tested_at' => null,
                'test_status' => 'untested',
                'test_message' => null,
            ],

            // Email SMTP
            [
                'provider' => 'smtp',
                'name' => 'Corporate SMTP Server',
                'category' => 'email',
                'description' => 'Transactional email delivery for invoices, password resets, and staff alerts.',
                'icon' => 'mail',
                'is_enabled' => true,
                'is_test_mode' => false,
                'credentials' => [
                    'host' => 'mail.aether-audio.test',
                    'port' => 587,
                    'encryption' => 'tls',
                    'username' => 'system@aether-audio.test',
                    'password' => 'MailSecret@2026',
                    'from_name' => 'AETHER Storefront',
                    'from_email' => 'system@aether-audio.test',
                ],
                'settings' => [],
                'last_tested_at' => now()->subMinutes(18),
                'test_status' => 'connected',
                'test_message' => 'SMTP handshake verified (port 587 TLS)',
            ],

            // WhatsApp
            [
                'provider' => 'whatsapp_business',
                'name' => 'WhatsApp Cloud API',
                'category' => 'whatsapp',
                'description' => 'Official Meta WhatsApp Business Cloud API for automated order updates and interactive customer service.',
                'icon' => 'whatsapp',
                'is_enabled' => true,
                'is_test_mode' => true,
                'credentials' => [
                    'phone_number_id' => '104928174920',
                    'whatsapp_business_account_id' => '99201948172',
                    'access_token' => 'EAAG9920194810294whp99201',
                    'webhook_verify_token' => 'aether_wh_token_2026',
                ],
                'settings' => [
                    'auto_confirm_hook' => true,
                    'send_invoice_pdf' => true,
                ],
                'last_tested_at' => now()->subHours(1),
                'test_status' => 'connected',
                'test_message' => 'Meta Cloud API v19.0 verified',
            ],

            // Analytics & Tracking Pixels
            [
                'provider' => 'google_tag_manager',
                'name' => 'Google Tag Manager (GTM)',
                'category' => 'analytics',
                'description' => 'Centralized tag management for Google Analytics 4, Google Ads conversions, and custom data layer events.',
                'icon' => 'gtm',
                'is_enabled' => true,
                'is_test_mode' => false,
                'credentials' => [
                    'container_id' => 'GTM-AETH789',
                ],
                'settings' => [
                    'enhanced_ecommerce' => true,
                    'data_layer_name' => 'dataLayer',
                ],
                'last_tested_at' => now(),
                'test_status' => 'connected',
                'test_message' => 'GTM script container verified',
            ],
            [
                'provider' => 'meta_pixel',
                'name' => 'Meta Pixel & CAPI',
                'category' => 'analytics',
                'description' => 'Facebook & Instagram Pixel tracking with Server-Side Conversions API (CAPI) deduplication.',
                'icon' => 'meta',
                'is_enabled' => true,
                'is_test_mode' => false,
                'credentials' => [
                    'pixel_id' => '9847291048201',
                    'access_token' => 'EAACcapi9812401823901',
                ],
                'settings' => [
                    'auto_pageview' => true,
                    'track_add_to_cart' => true,
                    'track_purchase' => true,
                ],
                'last_tested_at' => now(),
                'test_status' => 'connected',
                'test_message' => 'Pixel & CAPI operational',
            ],

            // Fraud Protection
            [
                'provider' => 'fraud_checker',
                'name' => 'Courier Fraud & Return Checker',
                'category' => 'fraud',
                'description' => 'Cross-checks customer phone number with courier databases to detect high-frequency return/cancellation abusers before dispatch.',
                'icon' => 'shield',
                'is_enabled' => true,
                'is_test_mode' => false,
                'credentials' => [
                    'api_key' => 'frd_guard_8819204',
                ],
                'settings' => [
                    'auto_flag_threshold_percent' => 35,
                    'minimum_deliveries_to_evaluate' => 3,
                ],
                'last_tested_at' => now()->subMinutes(30),
                'test_status' => 'connected',
                'test_message' => 'Fraud API connected. 0.05s average lookup',
            ],

            // Headless API & CORS
            [
                'provider' => 'headless_api',
                'name' => 'Headless API & CORS Configuration',
                'category' => 'api',
                'description' => 'Manage headless storefront web origins, CORS headers, rate limiting, and API authentication keys.',
                'icon' => 'code',
                'is_enabled' => true,
                'is_test_mode' => false,
                'credentials' => [
                    'primary_api_key' => 'inh_live_7a8f9c2d1e4b6093f8e12a4b8c9d0e1f3a5b7c9e',
                    'secondary_api_key' => null,
                ],
                'settings' => [
                    'allowed_origins' => [
                        'http://localhost:3000',
                        'http://127.0.0.1:3000',
                        'https://aether-ecommerce-frontend.vercel.app',
                    ],
                    'api_auth_enabled' => false,
                    'rate_limit_per_minute' => 60,
                ],
                'last_tested_at' => now(),
                'test_status' => 'connected',
                'test_message' => '3 allowed web origins validated. Dynamic CORS active.',
            ],

            // System & Edge Cache
            [
                'provider' => 'upstash_redis',
                'name' => 'Upstash Redis Cache',
                'category' => 'system',
                'description' => 'Serverless Redis integration for sub-millisecond edge caching, storefront navigation caching, and stampede protection.',
                'icon' => 'database',
                'is_enabled' => false,
                'is_test_mode' => false,
                'credentials' => [
                    'url' => '',
                    'token' => '',
                ],
                'settings' => [
                    'default_ttl' => 3600,
                    'cache_navigation' => true,
                    'cache_catalog' => true,
                ],
                'last_tested_at' => null,
                'test_status' => 'untested',
                'test_message' => 'Not configured yet',
            ],
        ];

        foreach ($integrations as $item) {
            Integration::updateOrCreate(
                ['provider' => $item['provider']],
                $item
            );
        }

        // 2. Seed Rich Settings
        $richSettings = [
            [
                'key' => 'shipping_zones',
                'group' => 'shipping',
                'type' => 'json',
                'label' => 'Shipping Zones & Rates',
                'value' => json_encode([
                    [
                        'id' => 'inside_dhaka',
                        'name' => 'Inside Dhaka Metro',
                        'rate' => 60,
                        'duration' => '24-48 Hours',
                        'free_threshold' => 3000,
                        'is_active' => true,
                    ],
                    [
                        'id' => 'dhaka_suburbs',
                        'name' => 'Dhaka Suburbs (Gazipur, Savar, Narayanganj)',
                        'rate' => 100,
                        'duration' => '48-72 Hours',
                        'free_threshold' => 5000,
                        'is_active' => true,
                    ],
                    [
                        'id' => 'outside_dhaka',
                        'name' => 'Outside Dhaka (Nationwide)',
                        'rate' => 130,
                        'duration' => '3-5 Business Days',
                        'free_threshold' => 6000,
                        'is_active' => true,
                    ],
                    [
                        'id' => 'express_sameday',
                        'name' => 'Express Same-Day Dispatch',
                        'rate' => 200,
                        'duration' => 'Same Day (Before 2 PM)',
                        'free_threshold' => 0,
                        'is_active' => true,
                    ],
                ]),
            ],
            [
                'key' => 'order_statuses',
                'group' => 'order',
                'type' => 'json',
                'label' => 'Order Status Pipeline',
                'value' => json_encode([
                    ['id' => 'pending', 'label' => 'Pending Confirmation', 'color' => 'amber', 'is_system' => true, 'sms_trigger' => false, 'email_trigger' => true],
                    ['id' => 'confirmed', 'label' => 'Confirmed', 'color' => 'blue', 'is_system' => true, 'sms_trigger' => true, 'email_trigger' => true],
                    ['id' => 'processing', 'label' => 'Packaging & Processing', 'color' => 'purple', 'is_system' => true, 'sms_trigger' => false, 'email_trigger' => false],
                    ['id' => 'in_courier', 'label' => 'Handed to Courier', 'color' => 'sky', 'is_system' => true, 'sms_trigger' => true, 'email_trigger' => true],
                    ['id' => 'delivered', 'label' => 'Delivered & Completed', 'color' => 'emerald', 'is_system' => true, 'sms_trigger' => true, 'email_trigger' => true],
                    ['id' => 'cancelled', 'label' => 'Cancelled by Customer / System', 'color' => 'rose', 'is_system' => true, 'sms_trigger' => true, 'email_trigger' => true],
                    ['id' => 'returned', 'label' => 'Returned & Restocked', 'color' => 'red', 'is_system' => true, 'sms_trigger' => false, 'email_trigger' => true],
                ]),
            ],
            [
                'key' => 'seo_meta',
                'group' => 'seo',
                'type' => 'json',
                'label' => 'SEO & Search Console',
                'value' => json_encode([
                    'meta_title' => 'AETHER | Precision Audio, Hi-Fi Acoustics & Studio Monitors',
                    'meta_description' => 'Discover studio-grade audiophile equipment, reference headphones, and handcrafted planar magnetic drivers engineered for acoustic clarity.',
                    'meta_keywords' => 'audiophile, headphones, studio monitors, planar magnetic, DAC, hi-fi, high resolution audio',
                    'google_site_verification' => 'google-site-verification-aether-992019481',
                    'bing_site_verification' => 'bing-site-auth-88192049182',
                    'og_image' => 'https://images.unsplash.com/photo-1546435770-a3e426bf472b?q=80&w=1200',
                    'canonical_base_url' => 'https://aether-audio.com',
                ]),
            ],
            [
                'key' => 'pwa_manifest',
                'group' => 'pwa',
                'type' => 'json',
                'label' => 'PWA Web App Manifest',
                'value' => json_encode([
                    'name' => 'AETHER Audio Labs',
                    'short_name' => 'AETHER',
                    'theme_color' => '#090b10',
                    'background_color' => '#07090e',
                    'display' => 'standalone',
                    'orientation' => 'portrait-primary',
                    'start_url' => '/?pwa=1',
                    'scope' => '/',
                    'icon_192' => '/icons/icon-192x192.png',
                    'icon_512' => '/icons/icon-512x512.png',
                ]),
            ],
            [
                'key' => 'notification_templates',
                'group' => 'notifications',
                'type' => 'json',
                'label' => 'Automated Notification Templates',
                'value' => json_encode([
                    'order_confirmed' => [
                        'title' => 'Order Confirmed',
                        'sms_body' => 'Hello {customer_name}, your order #{order_number} for {total_amount} has been confirmed. Track at {tracking_link}. Thanks, AETHER.',
                        'email_subject' => 'Order Confirmed #{order_number} - AETHER',
                    ],
                    'order_shipped' => [
                        'title' => 'Out for Delivery',
                        'sms_body' => 'Good news {customer_name}! Your order #{order_number} has been dispatched with courier {courier_name}. Tracking ID: {consignment_id}.',
                        'email_subject' => 'Your Order #{order_number} is on the way!',
                    ],
                    'order_delivered' => [
                        'title' => 'Order Delivered',
                        'sms_body' => 'Your order #{order_number} has been successfully delivered. Please leave a review at {review_link}. Enjoy your sound!',
                        'email_subject' => 'Delivered: Order #{order_number}',
                    ],
                    'order_cancelled' => [
                        'title' => 'Order Cancelled',
                        'sms_body' => 'Your order #{order_number} has been cancelled. If this was a mistake, please contact our helpline: {hotline}.',
                        'email_subject' => 'Order #{order_number} Cancellation Notice',
                    ],
                ]),
            ],
            [
                'key' => 'business_contact',
                'group' => 'general',
                'type' => 'json',
                'label' => 'Corporate Business & Contact Details',
                'value' => json_encode([
                    'legal_name' => 'AETHER Acoustics Ltd.',
                    'hotline' => '+880 1800-AETHER',
                    'support_email' => 'ops@aether-audio.test',
                    'whatsapp_number' => '+880 1711-998877',
                    'warehouse_address' => 'Plot 14, Tech Innovation Hub, Banani Block B, Dhaka 1213',
                    'order_prefix' => 'ORD-',
                    'invoice_prefix' => 'INV-',
                    'currency_symbol' => '৳',
                    'currency_code' => 'BDT',
                ]),
            ],
            [
                'key' => 'mail_senders',
                'group' => 'mail',
                'type' => 'json',
                'label' => 'Email Sender Routing',
                'value' => json_encode([
                    'order_confirmed'  => ['address' => '', 'name' => '', 'reply_to' => ''],
                    'order_shipped'    => ['address' => '', 'name' => '', 'reply_to' => ''],
                    'order_delivered'  => ['address' => '', 'name' => '', 'reply_to' => ''],
                    'order_cancelled'  => ['address' => '', 'name' => '', 'reply_to' => ''],
                    'auth'             => ['address' => '', 'name' => '', 'reply_to' => ''],
                    'staff_invitation' => ['address' => '', 'name' => '', 'reply_to' => ''],
                ]),
            ],
        ];

        foreach ($richSettings as $item) {
            Setting::updateOrCreate(
                ['key' => $item['key']],
                $item
            );
        }
    }
}
