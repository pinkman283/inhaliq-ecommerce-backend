<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\CustomerIpLog;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerRiskService
{
    /**
     * Calculate comprehensive customer metrics, transparent risk score, and signals.
     */
    public static function calculateCustomerRisk(User $customer): array
    {
        // 1. Gather all customer orders
        $orders = Order::where(function ($q) use ($customer) {
            $q->where('user_id', $customer->id)
              ->orWhere('customer_email', $customer->email);
        })->get();

        $totalOrders = $orders->count();
        $completedOrders = $orders->whereIn('order_status', ['delivered', 'shipped'])->count();
        $cancelledOrders = $orders->where('order_status', 'cancelled')->count();
        $refundedOrders = $orders->filter(fn($o) => $o->order_status === 'refunded' || $o->payment_status === 'refunded')->count();
        $failedOrders = $orders->where('payment_status', 'failed')->count();
        $totalSpent = (float) $orders->where('payment_status', 'paid')->sum('total_amount');
        $aov = $completedOrders > 0 ? round($totalSpent / $completedOrders, 2) : ($totalOrders > 0 ? round($totalSpent / $totalOrders, 2) : 0.00);

        $cancellationRate = $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 1) : 0.0;
        $refundRate = $totalOrders > 0 ? round(($refundedOrders / $totalOrders) * 100, 1) : 0.0;
        $failedRate = $totalOrders > 0 ? round(($failedOrders / $totalOrders) * 100, 1) : 0.0;

        // Dedicated Cash On Delivery (COD) Return & RTO Metrics
        $codOrders = $orders->filter(function ($o) {
            return in_array($o->payment_method, ['cash_on_delivery', 'cod', 'cash']) 
                || $o->payment_status === 'pending';
        });
        $codOrdersCount = $codOrders->count();
        $codDeliveredCount = $codOrders->where('order_status', 'delivered')->count();
        $codReturnedCount = $codOrders->filter(function ($o) {
            return in_array($o->order_status, ['returned', 'rto'])
                || in_array($o->return_status, ['returned', 'rto'])
                || ($o->order_status === 'cancelled' && ($o->shipped_at !== null || $o->tracking_code !== null));
        })->count();
        $codInTransitCount = $codOrders->whereIn('order_status', ['shipped', 'processing'])->count();

        $codReturnRate = $codOrdersCount > 0 ? round(($codReturnedCount / $codOrdersCount) * 100, 1) : 0.0;
        $codDeliveryRate = $codOrdersCount > 0 ? round(($codDeliveredCount / $codOrdersCount) * 100, 1) : 100.0;

        // Calculate Specific COD Return Risk Score (0-100)
        if ($codOrdersCount === 0) {
            $codRiskScore = 15;
            $codRiskLevel = 'low';
            $codRiskRecommendation = "First-time COD buyer. Standard order verification recommended before initial dispatch.";
        } else {
            if ($codReturnedCount >= 2 || $codReturnRate >= 40) {
                $codRiskScore = min(100, max(75, (int) round($codReturnRate + ($codReturnedCount * 10))));
                $codRiskLevel = 'high';
                $codRiskRecommendation = "High COD Return Risk ({$codReturnRate}% RTO rate). Require advance delivery charge deposit (৳120-150) before dispatching.";
            } elseif ($codReturnedCount >= 1 || $codReturnRate >= 15) {
                $codRiskScore = min(74, max(35, (int) round($codReturnRate * 1.5 + 20)));
                $codRiskLevel = 'moderate';
                $codRiskRecommendation = "Moderate COD Return Risk. Verify delivery address and contact number via phone call before dispatch.";
            } else {
                $codRiskScore = min(25, (int) round($codReturnRate));
                $codRiskLevel = 'low';
                $codRiskRecommendation = "Trusted COD buyer ({$codDeliveryRate}% delivery rate). Approved for 100% Cash on Delivery fulfillment.";
            }
        }

        // 2. Compute Risk Signals
        $score = 0;
        $reasons = [];

        // COD Return Penalty
        if ($codReturnedCount >= 2) {
            $score += 35;
            $reasons[] = "{$codReturnedCount} Cash-on-Delivery parcels returned to origin (RTO) - Return rate {$codReturnRate}%";
        } elseif ($codReturnedCount === 1) {
            $score += 15;
            $reasons[] = "1 Cash-on-Delivery parcel previously returned/cancelled after dispatch";
        }

        // Cancellation Frequency in time windows
        $now = now();
        $cancels24h = $orders->where('order_status', 'cancelled')->filter(fn($o) => $o->created_at >= $now->copy()->subHours(24))->count();
        $cancels7d = $orders->where('order_status', 'cancelled')->filter(fn($o) => $o->created_at >= $now->copy()->subDays(7))->count();
        $cancels30d = $orders->where('order_status', 'cancelled')->filter(fn($o) => $o->created_at >= $now->copy()->subDays(30))->count();

        if ($cancels24h >= 3) {
            $score += 40;
            $reasons[] = "{$cancels24h} order cancellations in the last 24 hours (High cancellation velocity)";
        } elseif ($cancels7d >= 5) {
            $score += 35;
            $reasons[] = "{$cancels7d} order cancellations in the last 7 days";
        } elseif ($cancels30d >= 10) {
            $score += 30;
            $reasons[] = "{$cancels30d} order cancellations in the last 30 days";
        }

        // High Cancellation Ratio
        if ($totalOrders >= 3 && $cancellationRate >= 60) {
            $score += 30;
            $reasons[] = "Cancellation rate is {$cancellationRate}% ({$cancelledOrders} of {$totalOrders} orders)";
        }

        // Frequent Payment Failures
        if ($failedOrders >= 2 && $failedRate >= 50) {
            $score += 25;
            $reasons[] = "High payment failure rate of {$failedRate}% ({$failedOrders} failed attempts)";
        }

        // Refund Ratio
        if ($refundedOrders >= 3 && $refundRate >= 40) {
            $score += 25;
            $reasons[] = "High refund rate of {$refundRate}% ({$refundedOrders} refunded orders)";
        }

        // Rapid order creation velocity (5+ orders placed within 1 hour)
        $ordersIn1h = $orders->filter(fn($o) => $o->created_at >= $now->copy()->subHour())->count();
        if ($ordersIn1h >= 5) {
            $score += 20;
            $reasons[] = "Rapid order placement: {$ordersIn1h} orders within 1 hour";
        }

        // Check if any IP used by customer is currently blocked
        $distinctIps = $orders->pluck('ip_address')->filter()->unique()->values();
        $hasBlockedIp = BlockedIp::whereIn('ip_address', $distinctIps)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($hasBlockedIp) {
            $score += 45;
            $reasons[] = "Order history contains an actively blocked IP address";
        }

        // Account status penalty
        if ($customer->status === 'blocked') {
            $score = max(85, $score + 50);
            $reasons[] = "Account is currently marked as BLOCKED by administrator";
        } elseif ($customer->status === 'suspended') {
            $score = max(65, $score + 30);
            $reasons[] = "Account is currently SUSPENDED";
        } elseif ($customer->status === 'review') {
            $score = max(40, $score + 15);
            $reasons[] = "Account is flagged for operational review";
        }

        // Cap score at 100 max
        $finalScore = min(100, max(0, $score));

        // Determine Level
        if ($finalScore >= 80) {
            $level = 'critical';
            $recommendation = "Immediate account restriction or block recommended. Abusive behavior pattern detected.";
        } elseif ($finalScore >= 60) {
            $level = 'high';
            $recommendation = "High risk detected. Recommend manual verification before fulfilling future orders.";
        } elseif ($finalScore >= 30) {
            $level = 'medium';
            $recommendation = "Moderate risk signals present. Monitor future cancellation and payment behavior.";
        } else {
            $level = 'low';
            $recommendation = "Account in good standing with standard transaction history.";
        }

        if (empty($reasons)) {
            $reasons[] = "Clean transaction history with no abnormal cancellation or dispute signals";
        }

        // Cache risk output on User model
        $customer->updateQuietly([
            'risk_level' => $level,
            'risk_score' => $finalScore,
            'risk_reasons' => $reasons,
        ]);

        return [
            'metrics' => [
                'total_orders' => $totalOrders,
                'completed_orders' => $completedOrders,
                'cancelled_orders' => $cancelledOrders,
                'refunded_orders' => $refundedOrders,
                'failed_orders' => $failedOrders,
                'cancellation_rate' => $cancellationRate,
                'refund_rate' => $refundRate,
                'failed_rate' => $failedRate,
                'total_spent' => $totalSpent,
                'aov' => $aov,
                'cancellations_24h' => $cancels24h,
                'cancellations_7d' => $cancels7d,
                'cancellations_30d' => $cancels30d,
            ],
            'risk' => [
                'score' => $finalScore,
                'level' => $level,
                'reasons' => $reasons,
                'recommendation' => $recommendation,
            ],
            'cod_risk' => [
                'score' => $codRiskScore,
                'level' => $codRiskLevel,
                'orders_count' => $codOrdersCount,
                'delivered_count' => $codDeliveredCount,
                'returned_count' => $codReturnedCount,
                'in_transit_count' => $codInTransitCount,
                'return_rate' => $codReturnRate,
                'delivery_rate' => $codDeliveryRate,
                'recommendation' => $codRiskRecommendation,
            ],
        ];
    }

    /**
     * Get multi-IP history for a specific customer.
     */
    public static function getCustomerIpHistory(User $customer): array
    {
        $orderIps = Order::where(function ($q) use ($customer) {
            $q->where('user_id', $customer->id)
              ->orWhere('customer_email', $customer->email);
        })
        ->whereNotNull('ip_address')
        ->select(
            'ip_address',
            DB::raw('MIN(created_at) as first_seen'),
            DB::raw('MAX(created_at) as last_seen'),
            DB::raw('COUNT(id) as total_orders'),
            DB::raw("SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders"),
            DB::raw("SUM(CASE WHEN order_status IN ('delivered', 'shipped') OR payment_status = 'paid' THEN 1 ELSE 0 END) as completed_orders"),
            DB::raw("SUM(CASE WHEN payment_status = 'failed' THEN 1 ELSE 0 END) as failed_orders")
        )
        ->groupBy('ip_address')
        ->get();

        // Also check customer_ip_logs for non-order activity IPs
        $logIps = CustomerIpLog::where('user_id', $customer->id)
            ->whereNotNull('ip_address')
            ->select(
                'ip_address',
                DB::raw('MIN(created_at) as first_seen'),
                DB::raw('MAX(created_at) as last_seen')
            )
            ->groupBy('ip_address')
            ->get();

        $mergedIps = [];

        foreach ($orderIps as $o) {
            $ip = $o->ip_address;
            $mergedIps[$ip] = [
                'ip_address' => $ip,
                'first_seen' => $o->first_seen,
                'last_seen' => $o->last_seen,
                'total_orders' => (int) $o->total_orders,
                'cancelled_orders' => (int) $o->cancelled_orders,
                'completed_orders' => (int) $o->completed_orders,
                'failed_orders' => (int) $o->failed_orders,
            ];
        }

        foreach ($logIps as $l) {
            $ip = $l->ip_address;
            if (!isset($mergedIps[$ip])) {
                $mergedIps[$ip] = [
                    'ip_address' => $ip,
                    'first_seen' => $l->first_seen,
                    'last_seen' => $l->last_seen,
                    'total_orders' => 0,
                    'cancelled_orders' => 0,
                    'completed_orders' => 0,
                    'failed_orders' => 0,
                ];
            } else {
                if (strtotime($l->first_seen) < strtotime($mergedIps[$ip]['first_seen'])) {
                    $mergedIps[$ip]['first_seen'] = $l->first_seen;
                }
                if (strtotime($l->last_seen) > strtotime($mergedIps[$ip]['last_seen'])) {
                    $mergedIps[$ip]['last_seen'] = $l->last_seen;
                }
            }
        }

        // Enrich with IP block status and co-tenant customer counts
        $result = [];
        foreach ($mergedIps as $ipData) {
            $ip = $ipData['ip_address'];
            $block = BlockedIp::where('ip_address', $ip)->first();
            $isActiveBlock = $block ? $block->isCurrentlyActive() : false;

            // Other distinct customers on this IP
            $otherCustomersCount = Order::where('ip_address', $ip)
                ->where(function ($q) use ($customer) {
                    $q->where('user_id', '!=', $customer->id)
                      ->orWhereNull('user_id');
                })
                ->where('customer_email', '!=', $customer->email)
                ->distinct('customer_email')
                ->count('customer_email');

            $result[] = array_merge($ipData, [
                'is_blocked' => $isActiveBlock,
                'block_details' => $isActiveBlock ? [
                    'id' => $block->id,
                    'reason' => $block->reason,
                    'expires_at' => $block->expires_at,
                ] : null,
                'other_customers_count' => $otherCustomersCount,
            ]);
        }

        usort($result, fn($a, $b) => strtotime($b['last_seen']) <=> strtotime($a['last_seen']));

        return $result;
    }

    /**
     * Get unified chronological activity timeline for customer.
     */
    public static function getCustomerActivityTimeline(User $customer): array
    {
        $events = [];

        // 1. Orders and state transitions
        $orders = Order::where(function ($q) use ($customer) {
            $q->where('user_id', $customer->id)
              ->orWhere('customer_email', $customer->email);
        })->latest()->get();

        foreach ($orders as $order) {
            // Order Placed Event
            $events[] = [
                'id' => 'order-created-' . $order->id,
                'timestamp' => $order->created_at->toIso8601String(),
                'type' => 'order_created',
                'title' => "Order #{$order->order_number} Placed",
                'description' => "Placed order for \${$order->total_amount} via {$order->payment_method} (" . ($order->items_count ?? $order->items()->count()) . " items).",
                'severity' => 'info',
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'total_amount' => $order->total_amount,
                    'ip_address' => $order->ip_address,
                    'payment_status' => $order->payment_status,
                ],
            ];

            // Cancelled Event
            if ($order->order_status === 'cancelled') {
                $events[] = [
                    'id' => 'order-cancelled-' . $order->id,
                    'timestamp' => $order->updated_at->toIso8601String(),
                    'type' => 'order_cancelled',
                    'title' => "Order #{$order->order_number} Cancelled",
                    'description' => $order->notes ?: "Order was marked as cancelled.",
                    'severity' => 'warning',
                    'metadata' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                    ],
                ];
            }

            // Refunded Event
            if ($order->order_status === 'refunded' || $order->payment_status === 'refunded') {
                $events[] = [
                    'id' => 'order-refunded-' . $order->id,
                    'timestamp' => $order->updated_at->toIso8601String(),
                    'type' => 'order_refunded',
                    'title' => "Order #{$order->order_number} Refunded",
                    'description' => "Payment of \${$order->total_amount} was refunded to customer.",
                    'severity' => 'warning',
                    'metadata' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'total_amount' => $order->total_amount,
                    ],
                ];
            }

            // Shipped Event
            if ($order->shipped_at) {
                $events[] = [
                    'id' => 'order-shipped-' . $order->id,
                    'timestamp' => $order->shipped_at->toIso8601String(),
                    'type' => 'order_shipped',
                    'title' => "Order #{$order->order_number} Dispatched",
                    'description' => "Shipped via {$order->carrier} (Tracking: {$order->tracking_code})",
                    'severity' => 'success',
                    'metadata' => [
                        'order_id' => $order->id,
                        'tracking_code' => $order->tracking_code,
                    ],
                ];
            }
        }

        // 2. Audit Log actions on this customer
        $auditLogs = AuditLog::where('target_type', 'User')
            ->where('target_id', $customer->id)
            ->latest()
            ->get();

        foreach ($auditLogs as $log) {
            $events[] = [
                'id' => 'audit-' . $log->id,
                'timestamp' => $log->created_at->toIso8601String(),
                'type' => $log->action,
                'title' => "Admin Action: " . ucwords(str_replace(['customer.', '_'], ['', ' '], $log->action)),
                'description' => $log->description,
                'severity' => in_array($log->action, ['customer.suspended', 'customer.blocked']) ? 'danger' : 'info',
                'metadata' => [
                    'actor_name' => $log->user?->name ?? 'System',
                    'ip_address' => $log->ip_address,
                ],
            ];
        }

        usort($events, fn($a, $b) => strtotime($b['timestamp']) <=> strtotime($a['timestamp']));

        return $events;
    }

    /**
     * Check if an IP address is currently blocked.
     */
    public static function isIpBlocked(string $ip): bool
    {
        $block = BlockedIp::where('ip_address', $ip)->first();
        if (!$block) {
            return false;
        }

        return $block->isCurrentlyActive();
    }

    /**
     * Block an IP address with customizable duration.
     */
    public static function blockIp(
        string $ip,
        string $reason,
        ?string $notes = null,
        ?User $actor = null,
        string $duration = 'permanent',
        ?string $customExpiresAt = null
    ): BlockedIp {
        $expiresAt = null;

        if ($duration === '1_hour') {
            $expiresAt = now()->addHour();
        } elseif ($duration === '24_hours') {
            $expiresAt = now()->addDay();
        } elseif ($duration === '7_days') {
            $expiresAt = now()->addDays(7);
        } elseif ($duration === '30_days') {
            $expiresAt = now()->addDays(30);
        } elseif ($duration === 'custom' && $customExpiresAt) {
            $expiresAt = Carbon::parse($customExpiresAt);
        }

        $blockedIp = BlockedIp::updateOrCreate(
            ['ip_address' => $ip],
            [
                'status' => 'active',
                'reason' => $reason,
                'notes' => $notes,
                'blocked_by_user_id' => $actor?->id,
                'expires_at' => $expiresAt,
            ]
        );

        if ($actor) {
            AuditLog::log(
                $actor,
                'security.ip_blocked',
                'BlockedIp',
                $blockedIp->id,
                "Blocked IP {$ip}. Reason: {$reason}. Duration: {$duration}" . ($expiresAt ? " (Expires: {$expiresAt->toDateTimeString()})" : " (Permanent)"),
                null,
                $blockedIp->toArray()
            );
        }

        return $blockedIp;
    }

    /**
     * Revoke an active IP block.
     */
    public static function unblockIp(string $ip, ?User $actor = null, ?string $reason = null): bool
    {
        $blockedIp = BlockedIp::where('ip_address', $ip)->first();
        if (!$blockedIp) {
            return false;
        }

        $oldValues = $blockedIp->toArray();
        $blockedIp->update(['status' => 'revoked']);

        if ($actor) {
            AuditLog::log(
                $actor,
                'security.ip_unblocked',
                'BlockedIp',
                $blockedIp->id,
                "Unblocked IP {$ip}." . ($reason ? " Reason: {$reason}" : ''),
                $oldValues,
                $blockedIp->toArray()
            );
        }

        return true;
    }
}
