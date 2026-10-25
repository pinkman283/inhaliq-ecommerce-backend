<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourierWebhookLog;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Courier\CourierManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CourierWebhookController extends Controller
{
    protected CourierManager $courierManager;

    public function __construct(CourierManager $courierManager)
    {
        $this->courierManager = $courierManager;
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));
        $payload = $request->all();
        $headers = $request->headers->all();
        $ip = $request->ip();

        Log::info("Inbound courier webhook [{$provider}]", ['payload' => $payload, 'ip' => $ip]);

        try {
            $driver = $this->courierManager->driver($provider);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => "Unknown provider [{$provider}]"], 400);
        }

        // 1. Signature & Authenticity Check
        if (!$driver->verifyWebhookSignature($request)) {
            Log::warning("Courier webhook signature verification failed for [{$provider}]", ['ip' => $ip]);
            return response()->json(['status' => 'error', 'message' => 'Invalid signature or token'], 401);
        }

        // 2. Parse Standardized Event
        $event = $driver->parseWebhook($request);
        if (!$event) {
            CourierWebhookLog::create([
                'provider' => $provider,
                'payload' => $payload,
                'headers' => $headers,
                'ip_address' => $ip,
                'status' => 'ignored',
                'error_message' => 'Could not identify consignment or order from payload.',
                'processed_at' => now(),
            ]);

            return response()->json(['status' => 'ignored', 'message' => 'Unrecognized payload structure']);
        }

        // 3. Idempotency & Replay Protection
        $existingLog = CourierWebhookLog::where('provider', $provider)
            ->where('consignment_id', $event->consignmentId)
            ->where('status', 'processed')
            ->where('event_type', $event->normalizedStatus)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->first();

        if ($existingLog) {
            CourierWebhookLog::create([
                'provider' => $provider,
                'event_type' => $event->normalizedStatus,
                'consignment_id' => $event->consignmentId,
                'tracking_code' => $event->trackingCode,
                'payload' => $payload,
                'headers' => $headers,
                'ip_address' => $ip,
                'status' => 'duplicate',
                'processed_at' => now(),
            ]);

            return response()->json(['status' => 'duplicate', 'message' => 'Event already processed']);
        }

        // 4. Locate Target Shipment
        $shipment = null;
        if (!empty($event->consignmentId)) {
            $shipment = Shipment::where('provider', $provider)
                ->where('consignment_id', $event->consignmentId)
                ->first();
        }

        if (!$shipment && !empty($event->trackingCode)) {
            $shipment = Shipment::where('provider', $provider)
                ->where('tracking_code', $event->trackingCode)
                ->first();
        }

        if (!$shipment && !empty($event->orderNumber)) {
            $order = Order::where('order_number', $event->orderNumber)->first();
            $shipment = $order?->latestShipment;
        }

        if (!$shipment) {
            CourierWebhookLog::create([
                'provider' => $provider,
                'event_type' => $event->normalizedStatus,
                'consignment_id' => $event->consignmentId,
                'tracking_code' => $event->trackingCode,
                'payload' => $payload,
                'headers' => $headers,
                'ip_address' => $ip,
                'status' => 'ignored',
                'error_message' => 'Matching shipment record not found in system.',
                'processed_at' => now(),
            ]);

            return response()->json(['status' => 'ignored', 'message' => 'Shipment not found']);
        }

        // 5. Update Shipment & Order State
        DB::transaction(function () use ($shipment, $event, $payload, $headers, $ip, $provider) {
            $oldStatus = $shipment->status;
            $newStatus = $event->normalizedStatus;

            // State Machine Guard: Prevent out-of-order webhooks from reverting terminal shipment states
            if (in_array($oldStatus, ['delivered', 'returned', 'cancelled']) && in_array($newStatus, ['booked', 'pickup_pending', 'picked_up', 'in_transit', 'out_for_delivery'])) {
                Log::info("Ignoring out-of-order courier webhook [{$provider}]: Shipment #{$shipment->id} is already in terminal state '{$oldStatus}', ignoring '{$newStatus}' transition.");
                return;
            }

            $updates = [
                'status' => $newStatus,
                'courier_status_raw' => $event->courierRawStatus ?: $shipment->courier_status_raw,
                'last_synced_at' => now(),
            ];

            if ($event->collectedAmount !== null && $event->collectedAmount > 0) {
                $updates['cod_amount'] = $event->collectedAmount;
            }
            if ($event->courierFee !== null && $event->courierFee > 0) {
                $updates['courier_charge'] = $event->courierFee;
            }

            if ($newStatus === 'in_transit' && !$shipment->in_transit_at) {
                $updates['in_transit_at'] = now();
            } elseif ($newStatus === 'out_for_delivery' && !$shipment->out_for_delivery_at) {
                $updates['out_for_delivery_at'] = now();
            } elseif ($newStatus === 'delivered') {
                $updates['delivered_at'] = $shipment->delivered_at ?: now();
            } elseif ($newStatus === 'returned') {
                $updates['returned_at'] = $shipment->returned_at ?: now();
                $updates['return_reason'] = $event->returnReason ?: $event->failureReason;
            } elseif ($newStatus === 'delivery_failed') {
                $updates['delivery_attempts'] = $shipment->delivery_attempts + 1;
                $updates['failure_reason'] = $event->failureReason ?: 'Delivery attempt unsuccessful';
            } elseif ($newStatus === 'cancelled') {
                $updates['cancelled_at'] = now();
            }

            $shipment->update($updates);

            // Commercial Order Updates
            $order = $shipment->order;
            if ($order) {
                if ($newStatus === 'delivered') {
                    $collected = (float) ($event->collectedAmount ?? $shipment->cod_amount);
                    $orderUpdates = [
                        'order_status' => 'delivered',
                        'delivered_at' => now(),
                        'amount_collected_courier' => $collected,
                    ];
                    if (in_array(strtolower($order->payment_method ?? ''), ['cash_on_delivery', 'cod'])) {
                        if ($collected >= (float) $order->total_amount) {
                            $orderUpdates['payment_status'] = 'paid';
                        } elseif ($collected > 0) {
                            $orderUpdates['payment_status'] = 'partially_paid';
                        }
                    }
                    $order->update($orderUpdates);
                } elseif (in_array($newStatus, ['returned', 'return_initiated'])) {
                    // Trigger automated RTO tracking via OrderReturnService
                    try {
                        app(\App\Services\OrderReturnService::class)->handleCourierRtoEvent($shipment, [
                            'reason' => $event->returnReason ?: $event->failureReason,
                            'collected_amount' => (float) ($event->collectedAmount ?? 0.00),
                            'rto_charge' => (float) ($event->courierFee ?? 0.00),
                        ]);
                    } catch (\Throwable $rtoEx) {
                        Log::warning("Automated RTO dispatch failed: " . $rtoEx->getMessage());
                    }
                } elseif ($newStatus === 'in_transit' && in_array($order->order_status, ['pending', 'confirmed'])) {
                    $order->update([
                        'order_status' => 'processing',
                        'shipped_at' => $order->shipped_at ?: now(),
                    ]);
                }

                $timelineIcon = match($newStatus) {
                    'delivered' => 'check',
                    'in_transit', 'out_for_delivery' => 'truck',
                    'delivery_failed' => 'alert',
                    'returned', 'return_initiated' => 'rotate',
                    default => 'check',
                };
                $timelineTitle = match($newStatus) {
                    'delivered' => 'Delivered to Customer',
                    'out_for_delivery' => 'Out for Delivery (Rider Dispatched)',
                    'in_transit' => 'In Transit with ' . ucfirst($provider),
                    'delivery_failed' => 'Delivery Attempt Failed',
                    'returned', 'return_initiated' => 'RTO Initiated by Courier',
                    default => 'Shipment Updated: ' . ucfirst(str_replace('_', ' ', $newStatus)),
                };
                if ($oldStatus !== $newStatus) {
                    \App\Services\OrderTimelineService::recordEvent(
                        order: $order,
                        eventType: $newStatus,
                        title: $timelineTitle,
                        description: $event->failureReason ?: "Courier ({$provider}) consignment {$shipment->consignment_id} updated to {$newStatus}.",
                        actorName: ucfirst($provider) . ' Webhook',
                        iconType: $timelineIcon,
                        metadata: ['provider' => $provider, 'consignment_id' => $shipment->consignment_id, 'raw_status' => $event->courierRawStatus]
                    );
                }
            }

            // Log successful webhook receipt
            CourierWebhookLog::create([
                'provider' => $provider,
                'event_type' => $newStatus,
                'consignment_id' => $shipment->consignment_id,
                'tracking_code' => $shipment->tracking_code,
                'payload' => $payload,
                'headers' => $headers,
                'ip_address' => $ip,
                'status' => 'processed',
                'processed_at' => now(),
            ]);

            if ($oldStatus !== $newStatus) {
                AuditLog::log(
                    null,
                    'courier.webhook_sync',
                    'Shipment',
                    $shipment->id,
                    "Webhook updated Consignment #{$shipment->consignment_id} ({$provider}) to [{$newStatus}]",
                    ['status' => $oldStatus],
                    ['status' => $newStatus, 'raw' => $event->courierRawStatus]
                );
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => "Shipment #{$shipment->consignment_id} synchronized to {$event->normalizedStatus}",
        ]);
    }
}
