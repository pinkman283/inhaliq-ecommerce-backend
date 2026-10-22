<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use App\Mail\OtpMail;
use App\Mail\AdminInvitationMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderDispatchedMail;
use App\Mail\OrderDeliveredMail;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;

class MailService
{
    /**
     * Configure the mail transport dynamically based on DB settings.
     */
    public static function configureTransport(): void
    {
        $config = Setting::get('mail_configuration', []);
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }
        
        if (empty($config)) {
            Log::warning('Mail configuration is empty. Using environment defaults.');
            return;
        }

        $mailer = $config['mailer'] ?? $config['MAIL_MAILER'] ?? env('MAIL_MAILER', 'smtp');
        Config::set('mail.default', $mailer);

        Config::set('mail.mailers.smtp.host', $config['host'] ?? $config['MAIL_HOST'] ?? env('MAIL_HOST', '127.0.0.1'));
        Config::set('mail.mailers.smtp.port', $config['port'] ?? $config['MAIL_PORT'] ?? env('MAIL_PORT', 2525));
        Config::set('mail.mailers.smtp.encryption', $config['encryption'] ?? $config['MAIL_ENCRYPTION'] ?? env('MAIL_ENCRYPTION', 'tls'));
        Config::set('mail.mailers.smtp.username', $config['username'] ?? $config['MAIL_USERNAME'] ?? env('MAIL_USERNAME'));
        Config::set('mail.mailers.smtp.password', $config['password'] ?? $config['MAIL_PASSWORD'] ?? env('MAIL_PASSWORD'));

        $fromAddress = $config['from_address'] ?? $config['MAIL_FROM_ADDRESS'] ?? env('MAIL_FROM_ADDRESS');
        $fromName = $config['from_name'] ?? $config['MAIL_FROM_NAME'] ?? env('MAIL_FROM_NAME');
        if ($fromAddress) {
            Config::set('mail.from.address', $fromAddress);
        }
        if ($fromName) {
            Config::set('mail.from.name', $fromName);
        }
    }

    /**
     * Get sender identity configuration.
     */
    public static function getSender(string $identity = 'general'): array
    {
        $senders = Setting::get('mail_senders', []);
        if (is_string($senders)) {
            $senders = json_decode($senders, true) ?: [];
        }

        $config = Setting::get('mail_configuration', []);
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }

        $default = [
            'address' => $config['MAIL_FROM_ADDRESS'] ?? $config['from_address'] ?? env('MAIL_FROM_ADDRESS', 'noreply@inhaliq.com'),
            'name'    => $config['MAIL_FROM_NAME'] ?? $config['from_name'] ?? env('MAIL_FROM_NAME', 'INHALIQ'),
        ];

        $entry = $senders[$identity] ?? [];

        return [
            'address'  => !empty($entry['address']) ? $entry['address'] : $default['address'],
            'name'     => !empty($entry['name'])    ? $entry['name']    : $default['name'],
            'reply_to' => !empty($entry['reply_to']) ? $entry['reply_to'] : null,
        ];
    }

    /**
     * Send OTP email.
     */
    public static function sendOtp(string $email, string $otp, string $purpose = 'registration'): void
    {
        self::configureTransport();
        $sender = self::getSender('auth');
        
        Config::set('mail.from.address', $sender['address']);
        Config::set('mail.from.name', $sender['name']);
        
        try {
            Mail::to($email)->send(new OtpMail($otp, $purpose));
        } catch (\Throwable $e) {
            Log::error("Failed to send OTP to {$email}: " . $e->getMessage());
        }
    }

    /**
     * Send Admin Invitation email.
     */
    public static function sendAdminInvitation(string $email, string $token, string $role): void
    {
        self::configureTransport();
        $sender = self::getSender('staff_invitation');
        
        Config::set('mail.from.address', $sender['address']);
        Config::set('mail.from.name', $sender['name']);
        
        try {
            Mail::to($email)->send(new AdminInvitationMail($token, $role));
        } catch (\Throwable $e) {
            Log::error("Failed to send Admin Invitation to {$email}: " . $e->getMessage());
        }
    }

    /**
     * Send order confirmation email to customer.
     */
    public static function sendOrderConfirmation(Order $order): void
    {
        if (empty($order->customer_email) || !filter_var($order->customer_email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        self::configureTransport();
        $sender = self::getSender('order_confirmed');
        
        Config::set('mail.from.address', $sender['address']);
        Config::set('mail.from.name', $sender['name']);
        
        try {
            Mail::to($order->customer_email)->send(new OrderConfirmationMail($order));
            Log::info("Order confirmation email sent to {$order->customer_email} for Order #{$order->order_number}");
        } catch (\Throwable $e) {
            Log::error("Failed to send order confirmation email to {$order->customer_email}: " . $e->getMessage());
        }
    }

    /**
     * Send shipment tracking email to customer.
     */
    public static function sendOrderDispatched(Order $order, Shipment $shipment): void
    {
        if (empty($order->customer_email) || !filter_var($order->customer_email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        self::configureTransport();
        $sender = self::getSender('order_shipped');
        
        Config::set('mail.from.address', $sender['address']);
        Config::set('mail.from.name', $sender['name']);
        
        try {
            Mail::to($order->customer_email)->send(new OrderDispatchedMail($order, $shipment));
            Log::info("Shipment dispatched email sent to {$order->customer_email} for Order #{$order->order_number}");
        } catch (\Throwable $e) {
            Log::error("Failed to send dispatch email to {$order->customer_email}: " . $e->getMessage());
        }
    }

    /**
     * Send delivery confirmation email to customer.
     */
    public static function sendOrderDelivered(Order $order): void
    {
        if (empty($order->customer_email) || !filter_var($order->customer_email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        self::configureTransport();
        $sender = self::getSender('order_delivered');
        
        Config::set('mail.from.address', $sender['address']);
        Config::set('mail.from.name', $sender['name']);
        
        try {
            Mail::to($order->customer_email)->send(new OrderDeliveredMail($order));
            Log::info("Order delivered email sent to {$order->customer_email} for Order #{$order->order_number}");
        } catch (\Throwable $e) {
            Log::error("Failed to send delivery email to {$order->customer_email}: " . $e->getMessage());
        }
    }
}

