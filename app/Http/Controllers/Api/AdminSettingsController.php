<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\Integration;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

class AdminSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'settings.manage');

        $settings = Setting::all()->keyBy('key');
        return response()->json($settings);
    }

    public function update(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'settings.manage');

        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*' => 'nullable',
        ]);

        $oldValues = [];
        $newValues = [];

        foreach ($validated['settings'] as $key => $val) {
            $setting = Setting::where('key', $key)->first();
            $valString = is_array($val) ? json_encode($val) : (string) $val;

            if ($setting) {
                $oldValues[$key] = $setting->value;
                $setting->update(['value' => $valString]);
                $newValues[$key] = $val;
            } else {
                Setting::create([
                    'key' => $key,
                    'value' => $valString,
                    'group' => 'general',
                    'label' => ucwords(str_replace('_', ' ', $key)),
                ]);
                $newValues[$key] = $val;
            }
        }

        // Keep VAT and Tax settings synchronized
        if (isset($newValues['vat_enabled'])) {
            $isVat = filter_var($newValues['vat_enabled'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
            Setting::set('vat_enabled', $isVat, 'tax', 'boolean', 'VAT Enabled');
            Setting::set('tax_enabled', $isVat, 'tax', 'boolean', 'Tax Enabled');
        }
        if (isset($newValues['vat_rate'])) {
            $rateStr = (string) $newValues['vat_rate'];
            Setting::set('vat_rate', $rateStr, 'tax', 'number', 'Default VAT Rate (%)');
            Setting::set('tax_rate', $rateStr, 'tax', 'number', 'Sales Tax Rate (%)');
        } elseif (isset($newValues['tax_rate']) && !isset($newValues['vat_rate'])) {
            $rateStr = (string) $newValues['tax_rate'];
            Setting::set('vat_rate', $rateStr, 'tax', 'number', 'Default VAT Rate (%)');
        }

        // Keep store_brand_name and split_reveal_title in sync if store_name is updated
        if (isset($newValues['store_name']) && !empty($newValues['store_name'])) {
            $brand = $newValues['store_name'];
            Setting::set('store_brand_name', $brand, 'theme', 'string', 'Store Brand Name');
            $currentSplitTitle = Setting::get('split_reveal_title', 'AETHER');
            $oldBrandName = $oldValues['store_name'] ?? 'AETHER';
            if ($currentSplitTitle === 'AETHER' || $currentSplitTitle === $oldBrandName) {
                Setting::set('split_reveal_title', $brand, 'theme', 'Split Reveal Title');
            }
        }

        // Synchronize shipping rates with shipping_zones
        if (isset($newValues['standard_shipping_rate']) || isset($newValues['priority_shipping_rate'])) {
            $zones = \App\Services\ShippingZoneResolver::getConfiguredZones();
            $changed = false;
            foreach ($zones as &$zone) {
                if ($zone['id'] === 'inside_dhaka' && isset($newValues['standard_shipping_rate'])) {
                    $zone['rate'] = (float) $newValues['standard_shipping_rate'];
                    $changed = true;
                }
                if ($zone['id'] === 'outside_dhaka' && isset($newValues['priority_shipping_rate'])) {
                    $zone['rate'] = (float) $newValues['priority_shipping_rate'];
                    $changed = true;
                }
            }
            if ($changed) {
                Setting::set('shipping_zones', json_encode($zones));
            }
        }

        \Illuminate\Support\Facades\Cache::forget('api_storefront_theme_settings');

        AuditLog::log(
            $request->user(),
            'settings.updated',
            'Setting',
            null,
            "Updated system store and operational settings.",
            $oldValues,
            $newValues
        );

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => Setting::all()->keyBy('key'),
        ]);
    }

    /**
     * Get all structured enterprise setting groups
     */
    public function getExtendedSettings(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'settings.manage');

        $settings = Setting::all()->keyBy('key');

        $decode = function ($key, $default = null) use ($settings) {
            if (!isset($settings[$key])) return $default;
            $val = $settings[$key]->value;
            $decoded = json_decode($val, true);
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $val;
        };

        return response()->json([
            'shipping_zones' => $decode('shipping_zones', []),
            'order_statuses' => $decode('order_statuses', []),
            'seo_meta' => $decode('seo_meta', []),
            'pwa_manifest' => $decode('pwa_manifest', []),
            'notification_templates' => $decode('notification_templates', []),
            'business_contact' => $decode('business_contact', []),
            'mail_senders' => $decode('mail_senders', []),
            'mail_configuration' => $decode('mail_configuration', []),
            'general' => [
                'store_name' => $settings['store_name']->value ?? 'AETHER Audio',
                'support_email' => $settings['support_email']->value ?? 'ops@aether-audio.test',
                'currency' => $settings['currency']->value ?? 'BDT',
                'tax_rate' => $settings['tax_rate']->value ?? ($settings['vat_rate']->value ?? '8.0'),
                'vat_rate' => $settings['vat_rate']->value ?? ($settings['tax_rate']->value ?? '8.0'),
                'vat_enabled' => isset($settings['vat_enabled']) ? filter_var($settings['vat_enabled']->value, FILTER_VALIDATE_BOOLEAN) : (isset($settings['tax_enabled']) ? filter_var($settings['tax_enabled']->value, FILTER_VALIDATE_BOOLEAN) : true),
                'free_shipping_threshold' => $settings['free_shipping_threshold']->value ?? '3000',
                'standard_shipping_rate' => $settings['standard_shipping_rate']->value ?? '60',
                'priority_shipping_rate' => $settings['priority_shipping_rate']->value ?? '120',
            ],
            'system_info' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'PHP CLI Server',
                'environment' => app()->environment(),
                'cache_driver' => config('cache.default', 'file'),
                'database_driver' => config('database.default'),
            ]
        ]);
    }

    /**
     * Save a specific settings group
     */
    public function updateGroup(Request $request, string $group): JsonResponse
    {
        $this->checkPermission($request, 'settings.manage');

        $data = $request->input('data');
        if (is_null($data)) {
            return response()->json(['message' => 'Data payload is required.'], 422);
        }

        $valString = is_array($data) ? json_encode($data) : (string) $data;

        $setting = Setting::updateOrCreate(
            ['key' => $group],
            [
                'value' => $valString,
                'group' => $group,
                'label' => ucwords(str_replace('_', ' ', $group)),
            ]
        );

        AuditLog::log(
            $request->user(),
            "settings.{$group}.updated",
            'Setting',
            $setting->id,
            "Updated configuration for group: {$group}"
        );

        if ($group === 'shipping_zones' && is_array($data)) {
            foreach ($data as $z) {
                if (($z['id'] ?? '') === 'inside_dhaka' && isset($z['rate'])) {
                    Setting::set('standard_shipping_rate', (string) $z['rate']);
                }
                if (($z['id'] ?? '') === 'outside_dhaka' && isset($z['rate'])) {
                    Setting::set('priority_shipping_rate', (string) $z['rate']);
                }
            }
            \Illuminate\Support\Facades\Cache::forget('api_storefront_theme_settings');
        }

        return response()->json([
            'message' => "Settings for '{$group}' saved successfully.",
            'data' => is_array($data) ? $data : json_decode($valString, true) ?? $valString,
        ]);
    }

    /**
     * Clear application cache, views, and routes
     */
    public function clearCache(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'settings.manage');

        $type = $request->input('type', 'all');
        $cleared = [];

        try {
            if ($type === 'all' || $type === 'cache') {
                Artisan::call('cache:clear');
                $cleared[] = 'Application Cache';
            }
            if ($type === 'all' || $type === 'config') {
                Artisan::call('config:clear');
                $cleared[] = 'Configuration Cache';
            }
            if ($type === 'all' || $type === 'route') {
                Artisan::call('route:clear');
                $cleared[] = 'Route Cache';
            }
            if ($type === 'all' || $type === 'view') {
                Artisan::call('view:clear');
                $cleared[] = 'Compiled Blade Views';
            }

            AuditLog::log(
                $request->user(),
                'system.cache_cleared',
                'System',
                null,
                "Flushed system caches: " . implode(', ', $cleared)
            );

            return response()->json([
                'success' => true,
                'message' => 'System caches successfully flushed: ' . implode(', ', $cleared),
                'cleared_components' => $cleared,
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to flush caches: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Dynamic sitemap XML generation
     */
    public function generateSitemap(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'settings.manage');

        $baseUrl = config('app.url', 'https://aether-audio.com');
        $urls = [];

        // Static routes
        $urls[] = ['loc' => $baseUrl . '/', 'priority' => '1.0', 'changefreq' => 'daily'];
        $urls[] = ['loc' => $baseUrl . '/shop', 'priority' => '0.9', 'changefreq' => 'daily'];
        $urls[] = ['loc' => $baseUrl . '/blog', 'priority' => '0.8', 'changefreq' => 'daily'];

        // Products
        $products = Product::select('id', 'slug', 'updated_at')->take(500)->get();
        foreach ($products as $p) {
            $slug = $p->slug ?? "product-{$p->id}";
            $urls[] = [
                'loc' => $baseUrl . '/products/' . $slug,
                'lastmod' => $p->updated_at ? $p->updated_at->toAtomString() : now()->toAtomString(),
                'priority' => '0.8',
                'changefreq' => 'weekly',
            ];
        }

        // Categories
        $categories = Category::select('id', 'slug', 'updated_at')->get();
        foreach ($categories as $c) {
            $urls[] = [
                'loc' => $baseUrl . '/category/' . $c->slug,
                'lastmod' => $c->updated_at ? $c->updated_at->toAtomString() : now()->toAtomString(),
                'priority' => '0.7',
                'changefreq' => 'weekly',
            ];
        }

        // Blog Posts
        $posts = BlogPost::select('id', 'slug', 'updated_at')->where('status', 'published')->get();
        foreach ($posts as $post) {
            $urls[] = [
                'loc' => $baseUrl . '/blog/' . $post->slug,
                'lastmod' => $post->updated_at ? $post->updated_at->toAtomString() : now()->toAtomString(),
                'priority' => '0.6',
                'changefreq' => 'monthly',
            ];
        }

        // CMS Pages
        $pages = CmsPage::select('id', 'slug', 'updated_at')->where('is_active', true)->get();
        foreach ($pages as $page) {
            $urls[] = [
                'loc' => $baseUrl . '/pages/' . $page->slug,
                'lastmod' => $page->updated_at ? $page->updated_at->toAtomString() : now()->toAtomString(),
                'priority' => '0.5',
                'changefreq' => 'monthly',
            ];
        }

        return response()->json([
            'success' => true,
            'total_urls' => count($urls),
            'sitemap_url' => url('/sitemap.xml'),
            'generated_at' => now()->toIso8601String(),
            'entries_sample' => array_slice($urls, 0, 15),
        ]);
    }

    /**
     * Public dynamic sitemap.xml endpoint
     */
    public function sitemapXml(): Response
    {
        $baseUrl = config('app.url', 'https://aether-audio.com');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        $addUrl = function ($loc, $lastmod = null, $changefreq = 'weekly', $priority = '0.8') use (&$xml) {
            $lastmod = $lastmod ?? date('Y-m-d');
            $xml .= '<url>';
            $xml .= '<loc>' . htmlspecialchars($loc) . '</loc>';
            $xml .= '<lastmod>' . $lastmod . '</lastmod>';
            $xml .= '<changefreq>' . $changefreq . '</changefreq>';
            $xml .= '<priority>' . $priority . '</priority>';
            $xml .= '</url>';
        };

        $addUrl($baseUrl . '/', date('Y-m-d'), 'daily', '1.0');
        $addUrl($baseUrl . '/shop', date('Y-m-d'), 'daily', '0.9');
        $addUrl($baseUrl . '/blog', date('Y-m-d'), 'daily', '0.8');

        $products = Product::select('id', 'slug', 'updated_at')->take(500)->get();
        foreach ($products as $p) {
            $slug = $p->slug ?? "product-{$p->id}";
            $addUrl($baseUrl . '/products/' . $slug, $p->updated_at ? $p->updated_at->format('Y-m-d') : null, 'weekly', '0.8');
        }

        $categories = Category::select('id', 'slug', 'updated_at')->get();
        foreach ($categories as $c) {
            $addUrl($baseUrl . '/category/' . $c->slug, $c->updated_at ? $c->updated_at->format('Y-m-d') : null, 'weekly', '0.7');
        }

        $posts = BlogPost::select('id', 'slug', 'updated_at')->where('status', 'published')->get();
        foreach ($posts as $post) {
            $addUrl($baseUrl . '/blog/' . $post->slug, $post->updated_at ? $post->updated_at->format('Y-m-d') : null, 'monthly', '0.6');
        }

        $pages = CmsPage::select('id', 'slug', 'updated_at')->where('is_active', true)->get();
        foreach ($pages as $page) {
            $addUrl($baseUrl . '/pages/' . $page->slug, $page->updated_at ? $page->updated_at->format('Y-m-d') : null, 'monthly', '0.5');
        }

        $xml .= '</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Public tracking scripts config (Meta Pixel, Google Tag Manager)
     */
    public function publicTrackingScripts(): JsonResponse
    {
        $gtm = Integration::where('provider', 'google_tag_manager')->where('is_enabled', true)->first();
        $meta = Integration::where('provider', 'meta_pixel')->where('is_enabled', true)->first();

        $metaSettings = $meta?->settings ?? [];

        return response()->json([
            'gtm' => [
                'enabled' => (bool) ($gtm && !empty($gtm->credentials['container_id'])),
                'container_id' => $gtm?->credentials['container_id'] ?? null,
                'data_layer_name' => $gtm?->settings['data_layer_name'] ?? 'dataLayer',
            ],
            'meta_pixel' => [
                'enabled' => (bool) ($meta && !empty($meta->credentials['pixel_id'])),
                'pixel_id' => $meta?->credentials['pixel_id'] ?? null,
                'settings' => [
                    'auto_pageview' => (bool) ($metaSettings['auto_pageview'] ?? true),
                    'track_view_content' => (bool) ($metaSettings['track_view_content'] ?? true),
                    'track_search' => (bool) ($metaSettings['track_search'] ?? true),
                    'track_add_to_cart' => (bool) ($metaSettings['track_add_to_cart'] ?? true),
                    'track_initiate_checkout' => (bool) ($metaSettings['track_initiate_checkout'] ?? true),
                    'track_purchase' => (bool) ($metaSettings['track_purchase'] ?? true),
                ],
            ],
        ]);
    }
}
