<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CustomerNotificationService
{
    /**
     * Send order confirmation email / SMS to customer
     */
    public static function sendOrderConfirmation(Order $order): void
    {
        $email = $order->customer_email;
        $phone = $order->customer_phone;

        Log::info("Dispatching Order Confirmation notification for Order #{$order->order_number} to [{$email}] [{$phone}]");

        try {
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                MailService::sendOrderConfirmation($order);
            }

            if ($phone) {
                // SMS dispatch hook for local BD SMS gateways (e.g., BulkSMS BD, Greenweb, Twilio)
                self::sendSms($phone, "Dear {$order->customer_name}, your order #{$order->order_number} (৳{$order->total_amount}) has been received successfully. We will call you soon to confirm dispatch.");
                
                // WhatsApp Automated Order Confirmation
                WhatsAppService::sendOrderConfirmation($order);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to send order confirmation notification: " . $e->getMessage());
        }
    }

    /**
     * Send dispatch / tracking notification to customer
     */
    public static function sendShipmentDispatched(Order $order, Shipment $shipment): void
    {
        $email = $order->customer_email;
        $phone = $order->customer_phone;

        Log::info("Dispatching Courier Tracking notification for Order #{$order->order_number} (Consignment: {$shipment->consignment_id}) to [{$email}]");

        try {
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                MailService::sendOrderDispatched($order, $shipment);
            }

            if ($phone) {
                $trackingInfo = $shipment->tracking_code ? "Tracking code: {$shipment->tracking_code}." : "";
                self::sendSms($phone, "Dear {$order->customer_name}, your parcel #{$order->order_number} is dispatched via {$shipment->provider}. {$trackingInfo} Please keep ৳{$shipment->cod_amount} ready for Cash on Delivery.");
                
                // WhatsApp Automated Dispatch & Tracking Notice
                WhatsAppService::sendOrderDispatched($order, $shipment);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to send shipment dispatch notification: " . $e->getMessage());
        }
    }

    /**
     * Send order delivery success notification
     */
    public static function sendOrderDelivered(Order $order): void
    {
        $email = $order->customer_email;
        $phone = $order->customer_phone;

        try {
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                MailService::sendOrderDelivered($order);
            }

            if ($phone) {
                self::sendSms($phone, "Dear {$order->customer_name}, your order #{$order->order_number} has been delivered successfully. Thank you for shopping with us!");
                
                // WhatsApp Automated Delivery Success Notice
                WhatsAppService::sendOrderDelivered($order);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to send order delivery notification: " . $e->getMessage());
        }
    }

    /**
     * Outbound SMS gateway adapter
     */
    protected static function sendSms(string $phone, string $message): bool
    {
        // Standardize Bangladeshi mobile number
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($cleanPhone, '880')) {
            $cleanPhone = '0' . substr($cleanPhone, 3);
        }

        Log::info("[SMS GATEWAY] Outbound SMS queued for [{$cleanPhone}]: \"{$message}\"");
        return true;
    }
}
