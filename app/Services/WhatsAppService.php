<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    /**
     * Send a raw text message via WhatsApp Cloud API
     */
    public static function sendTextMessage(string $phone, string $message): array
    {
        $integration = Integration::where('provider', 'whatsapp_business')->first();

        if (!$integration || !$integration->is_enabled) {
            Log::info("[WhatsApp] Integration disabled. Skipping message to {$phone}");
            return [
                'success' => false,
                'message' => 'WhatsApp Cloud API integration is currently disabled in settings.',
            ];
        }

        $creds = $integration->credentials ?? [];
        $phoneNumberId = trim((string) ($creds['phone_number_id'] ?? ''));
        $accessToken = trim((string) ($creds['access_token'] ?? ''));

        if (empty($phoneNumberId) || empty($accessToken)) {
            Log::warning("[WhatsApp] Missing phone_number_id or access_token for WhatsApp Cloud API.");
            return [
                'success' => false,
                'message' => 'WhatsApp Cloud API credentials (Phone Number ID / Access Token) are missing.',
            ];
        }

        $cleanPhone = self::normalizePhoneNumber($phone);
        if (strlen($cleanPhone) < 10) {
            return [
                'success' => false,
                'message' => "Invalid recipient phone number format: {$phone}",
            ];
        }

        // If in test mode with mock credentials, simulate success
        if ($integration->is_test_mode && str_starts_with($accessToken, 'EAAG9920194810294')) {
            Log::info("[WhatsApp TEST MODE] Simulated message to {$cleanPhone}: \"{$message}\"");
            return [
                'success' => true,
                'message' => "Simulated test message sent to {$cleanPhone} (Test Mode Active).",
                'message_id' => 'wamid.HBgL' . uniqid(),
            ];
        }

        try {
            $url = "https://graph.facebook.com/v21.0/{$phoneNumberId}/messages";

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $cleanPhone,
                'type' => 'text',
                'text' => [
                    'preview_url' => true,
                    'body' => $message,
                ],
            ];

            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            if ($response->successful()) {
                $resData = $response->json();
                $messageId = $resData['messages'][0]['id'] ?? 'wamid.unknown';
                Log::info("[WhatsApp] Sent message to {$cleanPhone}. Message ID: {$messageId}");

                return [
                    'success' => true,
                    'message' => "WhatsApp message delivered to {$cleanPhone}.",
                    'message_id' => $messageId,
                ];
            }

            $errorMsg = $response->json()['error']['message'] ?? 'Meta API error: ' . $response->body();
            Log::error("[WhatsApp API Error] {$errorMsg}");

            return [
                'success' => false,
                'message' => $errorMsg,
            ];
        } catch (\Throwable $e) {
            Log::error("[WhatsApp Exception] Failed to send message to {$cleanPhone}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send Order Confirmation alert
     */
    public static function sendOrderConfirmation(Order $order): array
    {
        $integration = Integration::where('provider', 'whatsapp_business')->first();
        if (!$integration || !$integration->is_enabled) {
            return ['success' => false, 'message' => 'Integration disabled'];
        }

        $settings = $integration->settings ?? [];
        if (isset($settings['auto_confirm_order']) && !$settings['auto_confirm_order']) {
            return ['success' => false, 'message' => 'Order confirmation WhatsApp toggle is turned off.'];
        }

        $phone = $order->customer_phone;
        if (!$phone) {
            return ['success' => false, 'message' => 'Order customer has no phone number'];
        }

        $appName = config('app.name', 'INHALIQ');
        $msg = "Hello {$order->customer_name}!\n\n"
             . "Thank you for shopping with {$appName}. Your order #{$order->order_number} has been received successfully!\n\n"
             . "📦 Order Summary:\n"
             . "• Total Amount: ৳" . number_format($order->total_amount, 2) . "\n"
             . "• Payment Method: " . strtoupper(str_replace('_', ' ', $order->payment_method ?? 'COD')) . "\n"
             . "• Delivery Address: " . (is_array($order->shipping_address) ? ($order->shipping_address['address'] ?? 'Dhaka') : 'Dhaka') . "\n\n"
             . "Our team is preparing your parcel. You will receive another message with tracking details when dispatched.";

        return self::sendTextMessage($phone, $msg);
    }

    /**
     * Send Order Dispatched with tracking code
     */
    public static function sendOrderDispatched(Order $order, ?Shipment $shipment = null): array
    {
        $integration = Integration::where('provider', 'whatsapp_business')->first();
        if (!$integration || !$integration->is_enabled) {
            return ['success' => false, 'message' => 'Integration disabled'];
        }

        $settings = $integration->settings ?? [];
        if (isset($settings['auto_dispatch_order']) && !$settings['auto_dispatch_order']) {
            return ['success' => false, 'message' => 'Order dispatch WhatsApp toggle is turned off.'];
        }

        $phone = $order->customer_phone;
        if (!$phone) {
            return ['success' => false, 'message' => 'Order customer has no phone number'];
        }

        $courier = $shipment->provider ?? ($order->carrier ?? 'Steadfast Courier');
        $tracking = $shipment->tracking_code ?? ($order->tracking_code ?? 'Pending');
        $appName = config('app.name', 'INHALIQ');

        $msg = "Great news {$order->customer_name}!\n\n"
             . "Your order #{$order->order_number} from {$appName} has been dispatched! 🚀\n\n"
             . "🚚 Courier Details:\n"
             . "• Delivery Partner: {$courier}\n"
             . "• Tracking Code: {$tracking}\n"
             . "• Cash to Collect (COD): ৳" . number_format($order->total_amount, 2) . "\n\n"
             . "Please keep the cash ready. Our courier agent will call you before delivery.";

        return self::sendTextMessage($phone, $msg);
    }

    /**
     * Send Order Delivered confirmation
     */
    public static function sendOrderDelivered(Order $order): array
    {
        $integration = Integration::where('provider', 'whatsapp_business')->first();
        if (!$integration || !$integration->is_enabled) {
            return ['success' => false, 'message' => 'Integration disabled'];
        }

        $phone = $order->customer_phone;
        if (!$phone) {
            return ['success' => false, 'message' => 'Order customer has no phone number'];
        }

        $appName = config('app.name', 'INHALIQ');
        $msg = "Dear {$order->customer_name},\n\n"
             . "Your parcel for order #{$order->order_number} has been delivered successfully! 🎉\n\n"
             . "We hope you love your purchase. If you have any questions or feedback, simply reply to this message.\n\n"
             . "Thank you for shopping with {$appName}!";

        return self::sendTextMessage($phone, $msg);
    }

    /**
     * Send Abandoned Cart Recovery Message
     */
    public static function sendAbandonedCartRecovery(Lead $lead, ?string $discountCode = null): array
    {
        $phone = $lead->phone ?? ($lead->customer_phone ?? '');
        if (!$phone) {
            return ['success' => false, 'message' => 'Lead has no phone number.'];
        }

        $name = $lead->name ?? 'there';
        $appName = config('app.name', 'INHALIQ');
        $storeUrl = config('app.url', 'http://localhost:3000');
        $cartLink = rtrim($storeUrl, '/') . '/cart';

        $discountLine = $discountCode 
            ? "🎁 Use voucher code *{$discountCode}* at checkout to enjoy a special discount!\n\n" 
            : "";

        $msg = "Hi {$name}!\n\n"
             . "We noticed you left some great items in your shopping cart at {$appName}. 🛒\n\n"
             . $discountLine
             . "Items in your cart are in high demand and might sell out soon.\n"
             . "Complete your order here: {$cartLink}\n\n"
             . "Need help? Reply to this message and our support team will assist you!";

        return self::sendTextMessage($phone, $msg);
    }

    /**
     * Format phone number to international E.164 without '+'
     */
    public static function normalizePhoneNumber(string $phone): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);

        // Bangladeshi local 01xxxxxxxxx format -> 8801xxxxxxxxx
        if (str_starts_with($clean, '01') && strlen($clean) === 11) {
            $clean = '88' . $clean;
        }

        return $clean;
    }
}
