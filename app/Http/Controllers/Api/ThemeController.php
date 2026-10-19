<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ThemeController extends Controller
{
    /**
     * Get public-facing storefront theme and UI settings.
     */
    public function publicSettings(): JsonResponse
    {
        $themeSettings = Cache::remember('api_storefront_theme_settings', 60, function () {
            $settings = Setting::all()->keyBy('key');

            $get = function (string $key, mixed $default = null) use ($settings) {
                if (!$settings->has($key)) {
                    return $default;
                }
                $setting = $settings->get($key);
                return match ($setting->type) {
                    'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
                    'number' => is_numeric($setting->value) ? (float) $setting->value : $default,
                    'json' => json_decode($setting->value, true) ?? $default,
                    default => $setting->value,
                };
            };

            $logoPlacements = [];
            try {
                $rawPlacements = \App\Models\BrandLogoPlacement::with('logo')->get();
                foreach ($rawPlacements as $bp) {
                    if ($bp->logo && !empty($bp->logo->image_url)) {
                        $logoPlacements[$bp->placement] = $bp->logo->image_url;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore if tables not ready
            }

            $effectiveNavbarLogo = $logoPlacements['navbar'] ?? $get('store_brand_logo', '');
            $effectiveSplitLogo = $logoPlacements['split_reveal'] ?? $get('split_reveal_logo', '');

            return [
                'currency' => $get('currency', 'BDT'),
                'currency_symbol' => $get('currency_symbol', '৳'),
                'theme_primary_color' => $get('theme_primary_color', '#005826'),
                'theme_secondary_color' => $get('theme_secondary_color', '#2da54b'),
                'theme_accent_gradient' => $get('theme_accent_gradient', 'emerald-teal'),
                'theme_bg_color' => $get('theme_bg_color', '#ffffff'),
                'theme_card_bg_color' => $get('theme_card_bg_color', '#ffffff'),
                'theme_card_border_color' => $get('theme_card_border_color', '#e5e7eb'),
                'theme_text_heading_color' => $get('theme_text_heading_color', '#0f172a'),
                'theme_text_body_color' => $get('theme_text_body_color', '#475569'),
                'theme_btn_primary_bg' => $get('theme_btn_primary_bg', '#005826'),
                'theme_btn_primary_text' => $get('theme_btn_primary_text', '#ffffff'),
                'theme_btn_secondary_bg' => $get('theme_btn_secondary_bg', '#f3f4f6'),
                'theme_btn_secondary_text' => $get('theme_btn_secondary_text', '#0f172a'),
                'theme_tab_active_bg' => $get('theme_tab_active_bg', '#005826'),
                'theme_tab_active_text' => $get('theme_tab_active_text', '#ffffff'),
                'theme_view_all_color' => $get('theme_view_all_color', $get('theme_tab_active_bg', '#005826')),
                'theme_hover_bg' => $get('theme_hover_bg', 'rgba(0, 88, 38, 0.08)'),
                'theme_hover_text' => $get('theme_hover_text', '#005826'),
                'theme_nav_btn_bg' => $get('theme_nav_btn_bg', '#ffffff'),
                'theme_nav_btn_color' => $get('theme_nav_btn_color', '#0f172a'),
                'theme_footer_bg_color' => $get('theme_footer_bg_color', '#0f172a'),
                'theme_footer_text_color' => $get('theme_footer_text_color', '#94a3b8'),
                'theme_radius' => $get('theme_radius', 'rounded-xl'),
                'announcement_enabled' => $get('announcement_enabled', true),
                'announcement_text' => $get('announcement_text', 'Free Express Courier on orders over ৳2,000 • Code: AETHER10 (-10%)'),
                'announcement_badge' => $get('announcement_badge', 'Fast Dispatch: Daily Courier Active'),
                'navbar_promo_enabled' => (bool) $get('navbar_promo_enabled', true),
                'navbar_promo_discount_text' => $get('navbar_promo_discount_text', '20% OFF'),
                'navbar_promo_code' => $get('navbar_promo_code', 'AETHER10'),
                'navbar_promo_link' => $get('navbar_promo_link', '/promotions'),
                'nav_deals_enabled' => (bool) $get('nav_deals_enabled', true),
                'nav_deals_text' => $get('nav_deals_text', 'Deals'),
                'nav_deals_link' => $get('nav_deals_link', '/products?discounted=true'),
                'category_deals_card_enabled' => (bool) $get('category_deals_card_enabled', true),
                'category_deals_card_title' => $get('category_deals_card_title', 'Top Deals'),
                'category_deals_card_subtitle' => $get('category_deals_card_subtitle', 'Up to 20% Off'),
                'category_deals_card_link' => $get('category_deals_card_link', '/products?discounted=true'),
                'footer_features_enabled' => (bool) $get('footer_features_enabled', true),
                'footer_feature_title_1' => $get('footer_feature_title_1', 'Free Express Shipping'),
                'footer_feature_desc_1' => $get('footer_feature_desc_1', 'Complimentary delivery inside & outside Dhaka.'),
                'footer_feature_link_1' => $get('footer_feature_link_1', '/shipping-policy'),
                'footer_feature_title_2' => $get('footer_feature_title_2', '2-Year Studio Warranty'),
                'footer_feature_desc_2' => $get('footer_feature_desc_2', 'Comprehensive hardware protection & zero-cost repair.'),
                'footer_feature_link_2' => $get('footer_feature_link_2', '/refund-policy'),
                'footer_feature_title_3' => $get('footer_feature_title_3', '30-Day Risk-Free Trial'),
                'footer_feature_desc_3' => $get('footer_feature_desc_3', 'Hassle-free evaluation with prepaid RMA labels.'),
                'footer_feature_link_3' => $get('footer_feature_link_3', '/refund-policy'),
                'footer_feature_title_4' => $get('footer_feature_title_4', '24/7 Audio Support'),
                'footer_feature_desc_4' => $get('footer_feature_desc_4', 'Direct access to sound engineers & hardware specialists.'),
                'footer_feature_link_4' => $get('footer_feature_link_4', '/contact'),
                'reviews_enabled' => (bool) $get('reviews_enabled', true),
                'vat_enabled' => Setting::isVatEnabled(),
                'vat_rate' => Setting::getVatRate(),
                'shipping_inside_dhaka_rate' => (float) ($get('standard_shipping_rate', 60.0)),
                'shipping_outside_dhaka_rate' => (float) ($get('priority_shipping_rate', 120.0)),
                'shipping_free_threshold' => (float) ($get('free_shipping_threshold', 3000.0)),
                'store_brand_name' => $brandName = $get('store_brand_name', 'INHALIQ'),
                'store_brand_tagline' => $get('store_brand_tagline', 'ELEVATE EVERY INHALE'),
                'store_brand_logo' => $effectiveNavbarLogo,
                'store_favicon' => $get('store_favicon', ''),
                'logo_placements' => $logoPlacements,
                'hero_headline_line1' => $get('hero_headline_line1', 'Uncompromising'),
                'hero_headline_line2_gradient' => $get('hero_headline_line2_gradient', 'Industrial Audio'),
                'hero_headline_line3' => $get('hero_headline_line3', '& Tech Ecosystem.'),
                'hero_subheading' => $get('hero_subheading', 'Engineered with aerospace-grade titanium, custom beryllium drivers, and tactile mechanical acoustics for creators who refuse mediocrity.'),
                'hero_badge_text' => $get('hero_badge_text', '2026 Studio Flagship Release'),
                'split_reveal_enabled' => $get('split_reveal_enabled', true),
                'split_reveal_image' => $get('split_reveal_image', 'https://images.unsplash.com/photo-1550745165-9bc0b252726f?auto=format&fit=crop&w=2000&q=85'),
                'split_reveal_logo' => $effectiveSplitLogo,
                'split_reveal_title' => ($get('split_reveal_title') && $get('split_reveal_title') !== 'AETHER') ? $get('split_reveal_title') : $brandName,
                'split_reveal_subtitle' => $get('split_reveal_subtitle', 'PRECISION ACOUSTICS & HARDWARE'),
                'split_reveal_duration' => (float) $get('split_reveal_duration', 2.2),
                'split_reveal_mode' => $get('split_reveal_mode', 'every_time'),
                'split_reveal_dim' => (float) $get('split_reveal_dim', 0.45),
                'split_reveal_direction' => $get('split_reveal_direction', 'vertical'),
                'customer_auth_bg_image' => $get('customer_auth_bg_image', ''),
                'customer_auth_bg_color' => $get('customer_auth_bg_color', '#ffffff'),
                'customer_auth_card_position' => $get('customer_auth_card_position', 'left'),
            ];
        });
        return response()->json($themeSettings);
    }
}
