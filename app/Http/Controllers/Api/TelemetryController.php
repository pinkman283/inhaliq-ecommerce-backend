<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StorefrontPageView;
use App\Models\TrafficSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelemetryController extends Controller
{
    /**
     * Record a storefront page view and visitor session
     */
    public function recordPageView(Request $request): JsonResponse
    {
        $sessionId = $request->input('session_id') ?: Str::uuid()->toString();
        $visitorId = $request->input('visitor_id') ?: $sessionId;
        $urlPath = $request->input('url_path', '/');
        $pageTitle = $request->input('page_title', 'Storefront');
        $referrer = $request->input('referrer', '');
        $utmSource = $request->input('utm_source');
        $utmMedium = $request->input('utm_medium');
        $utmCampaign = $request->input('utm_campaign');
        $deviceInput = $request->input('device_type');
        $domain = $request->getHost() ?: 'www.inhaliq.com';

        // Detect device type
        $userAgent = $request->userAgent() ?: '';
        $deviceType = 'desktop';
        if ($deviceInput && in_array(strtolower($deviceInput), ['mobile', 'desktop', 'tablet'])) {
            $deviceType = strtolower($deviceInput);
        } else {
            if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $userAgent)) {
                $deviceType = 'tablet';
            } elseif (preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', $userAgent)) {
                $deviceType = 'mobile';
            }
        }

        // Determine Acquisition Channel
        $channel = $this->determineChannel($referrer, $utmSource, $utmMedium);

        // Determine Page Type
        $pageType = $this->determinePageType($urlPath);

        // Create or update session
        TrafficSession::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'visitor_id' => $visitorId,
                'ip_address' => $request->ip(),
                'user_agent' => substr($userAgent, 0, 500),
                'device_type' => $deviceType,
                'channel' => $channel,
                'referrer_source' => $referrer ? substr($referrer, 0, 250) : 'Direct',
                'landing_page' => substr($urlPath, 0, 250),
                'utm_source' => $utmSource ? substr($utmSource, 0, 99) : null,
                'utm_medium' => $utmMedium ? substr($utmMedium, 0, 99) : null,
                'utm_campaign' => $utmCampaign ? substr($utmCampaign, 0, 99) : null,
                'domain' => $domain,
            ]
        );

        // Record page view
        StorefrontPageView::create([
            'session_id' => $sessionId,
            'visitor_id' => $visitorId,
            'url_path' => substr($urlPath, 0, 250),
            'page_title' => substr($pageTitle, 0, 250),
            'page_type' => $pageType,
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'session_id' => $sessionId,
            'visitor_id' => $visitorId,
            'channel' => $channel,
        ]);
    }

    private function determineChannel(?string $referrer, ?string $utmSource, ?string $utmMedium): string
    {
        $source = strtolower($utmSource ?? '');
        $medium = strtolower($utmMedium ?? '');
        $ref = strtolower($referrer ?? '');

        if ($medium === 'cpc' || str_contains($source, 'googleads') || str_contains($ref, 'adservice.google')) {
            return 'Google Ads';
        }
        if (str_contains($source, 'maps') || str_contains($ref, 'maps.google')) {
            return 'Google Maps';
        }
        if (str_contains($source, 'google') || str_contains($ref, 'google.')) {
            return 'Google Organic';
        }
        if (str_contains($source, 'facebook') || str_contains($source, 'fb') || str_contains($source, 'instagram') || str_contains($ref, 'facebook.com') || str_contains($ref, 'instagram.com')) {
            return 'Facebook';
        }
        if (str_contains($source, 'tiktok') || str_contains($ref, 'tiktok.com')) {
            return 'TikTok';
        }
        if (str_contains($source, 'youtube') || str_contains($ref, 'youtube.com')) {
            return 'YouTube';
        }
        if (str_contains($source, 'whatsapp') || str_contains($ref, 'whatsapp')) {
            return 'WhatsApp';
        }
        if (str_contains($source, 'sms')) {
            return 'SMS';
        }
        if (str_contains($source, 'app') || str_contains($medium, 'api')) {
            return 'Mobile App / API';
        }
        if ($referrer && !str_contains($ref, 'localhost') && !str_contains($ref, 'inhaliq') && !str_contains($ref, '127.0.0.1')) {
            return 'Referral';
        }

        return 'Direct';
    }

    private function determinePageType(string $path): string
    {
        $p = strtolower(trim($path, '/'));
        if ($p === '' || $p === 'home') {
            return 'Home';
        }
        if (str_starts_with($p, 'category/')) {
            return 'Category';
        }
        if (str_starts_with($p, 'subcategory/')) {
            return 'Subcategory';
        }
        if (str_starts_with($p, 'child-category/')) {
            return 'Child Category';
        }
        if (str_starts_with($p, 'product/')) {
            return 'Product';
        }
        return 'Other';
    }
}
