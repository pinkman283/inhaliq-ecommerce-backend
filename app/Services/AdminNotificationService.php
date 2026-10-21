<?php

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class AdminNotificationService
{
    /**
     * Dispatch an internal notification to all authorized admins and staff.
     */
    public static function send(
        string $category,
        string $title,
        string $message,
        ?string $actionUrl = null,
        array $metadata = [],
        string $severity = 'info',
        ?string $requiredPermission = null
    ): int {
        try {
            // Retrieve all active users who are administrators or staff
            $recipients = User::whereIn('role', ['super_admin', 'admin', 'staff'])
                ->where('status', 'active')
                ->get();

            $eligibleUserIds = [];

            foreach ($recipients as $user) {
                // Super admins and admins have global access to all notifications
                if ($user->isSuperAdmin() || $user->isAdmin()) {
                    $eligibleUserIds[] = $user->id;
                    continue;
                }

                // If a specific permission is required, check staff permissions
                if ($requiredPermission) {
                    if ($user->hasPermission($requiredPermission)) {
                        $eligibleUserIds[] = $user->id;
                    }
                } else {
                    // General/system notification
                    $eligibleUserIds[] = $user->id;
                }
            }

            if (empty($eligibleUserIds)) {
                return 0;
            }

            $now = now();
            $records = [];
            $encodedMetadata = !empty($metadata) ? json_encode($metadata) : null;

            foreach ($eligibleUserIds as $userId) {
                $records[] = [
                    'user_id' => $userId,
                    'category' => $category,
                    'severity' => $severity,
                    'title' => $title,
                    'message' => $message,
                    'action_url' => $actionUrl,
                    'required_permission' => $requiredPermission,
                    'metadata' => $encodedMetadata,
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            AdminNotification::insert($records);

            return count($records);
        } catch (\Throwable $e) {
            Log::error("Failed to dispatch admin notification: " . $e->getMessage(), [
                'exception' => $e,
                'category' => $category,
                'title' => $title,
            ]);
            return 0;
        }
    }

    /**
     * Notify authorized staff when a new checkout lead arrives.
     */
    public static function notifyNewLead(Lead $lead): int
    {
        $itemCount = is_array($lead->cart_items) ? count($lead->cart_items) : 0;
        $formattedAmount = number_format((float) ($lead->total_amount ?? 0), 2);
        $customerName = !empty($lead->name) ? $lead->name : 'Unknown Customer';
        $phone = !empty($lead->phone) ? " ({$lead->phone})" : '';

        return self::send(
            category: 'leads',
            title: 'New Checkout Lead Captured',
            message: "{$customerName}{$phone} abandoned checkout with {$itemCount} item(s). Value: ৳{$formattedAmount}.",
            actionUrl: "/admin/leads?id={$lead->id}",
            metadata: [
                'lead_id' => $lead->id,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'total_amount' => $lead->total_amount,
                'item_count' => $itemCount,
            ],
            severity: 'info',
            requiredPermission: 'leads.view'
        );
    }

    /**
     * Notify authorized staff when a new order is placed.
     */
    public static function notifyNewOrder(Order $order): int
    {
        $formattedAmount = number_format((float) $order->total_amount, 2);
        $isHighValue = (float) $order->total_amount >= 25000;

        return self::send(
            category: 'orders',
            title: $isHighValue ? '🔥 High-Value Order Placed' : 'New Order Placed',
            message: "Order #{$order->order_number} by {$order->customer_name} for ৳{$formattedAmount} ({$order->payment_method}).",
            actionUrl: "/admin/orders/{$order->id}",
            metadata: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'amount' => $order->total_amount,
                'payment_method' => $order->payment_method,
            ],
            severity: $isHighValue ? 'warning' : 'success',
            requiredPermission: 'orders.view'
        );
    }

    /**
     * Notify when inventory falls below minimum safety stock.
     */
    public static function notifyLowStock(Product $product, ?ProductVariant $variant = null, int $stock = 0): int
    {
        $itemName = $variant ? "Variant '{$variant->name}' of '{$product->name}'" : "Product '{$product->name}'";
        $isOutOfStock = $stock <= 0;

        return self::send(
            category: 'inventory',
            title: $isOutOfStock ? '⚠️ Out of Stock Alert' : 'Low Stock Warning',
            message: "{$itemName} is down to {$stock} unit(s) remaining.",
            actionUrl: "/admin/products/inventory",
            metadata: [
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'current_stock' => $stock,
            ],
            severity: $isOutOfStock ? 'critical' : 'warning',
            requiredPermission: 'inventory.manage'
        );
    }
}
