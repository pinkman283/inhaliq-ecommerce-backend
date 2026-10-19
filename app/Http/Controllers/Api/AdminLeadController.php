<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminLeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'leads.view');

        $query = Lead::with(['user', 'convertedOrder'])->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        // Calculate KPI Metrics across all leads (or matching date filter)
        $kpiQuery = Lead::query();
        if ($request->filled('date_from')) {
            $kpiQuery->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $kpiQuery->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $totalLeads = $kpiQuery->count();
        $pipelineValue = (float) Lead::whereIn('status', ['new', 'contacted', 'in_progress'])->sum('total_amount');
        $newLeadsCount = Lead::where('status', 'new')->count();
        $contactedCount = Lead::where('status', 'contacted')->count();
        $inProgressCount = Lead::where('status', 'in_progress')->count();
        $convertedCount = Lead::where('status', 'converted')->count();
        $lostCount = Lead::where('status', 'lost')->count();
        $conversionRate = $totalLeads > 0 ? round(($convertedCount / $totalLeads) * 100, 1) : 0.0;

        $perPage = (int) $request->input('per_page', 25);
        $leads = $query->paginate($perPage);

        return response()->json([
            'leads' => $leads,
            'stats' => [
                'total_leads' => $totalLeads,
                'pipeline_value' => $pipelineValue,
                'new_leads_count' => $newLeadsCount,
                'contacted_count' => $contactedCount,
                'in_progress_count' => $inProgressCount,
                'converted_count' => $convertedCount,
                'lost_count' => $lostCount,
                'conversion_rate' => $conversionRate,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'leads.view');

        $lead = Lead::with(['user', 'convertedOrder'])->findOrFail($id);
        return response()->json($lead);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'leads.manage');

        $lead = Lead::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:50',
            'email' => 'nullable|string|email|max:255',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:50',
            'status' => 'sometimes|required|in:new,contacted,in_progress,converted,lost',
            'notes' => 'nullable|string|max:5000',
            'total_amount' => 'nullable|numeric|min:0',
        ]);

        $oldStatus = $lead->status;
        $lead->update($validated);

        AuditLog::log(
            $request->user(),
            'lead.updated',
            'Lead',
            $lead->id,
            "Updated lead record #{$lead->id} ({$lead->name}, {$lead->phone}). Status: {$oldStatus} -> {$lead->status}",
            ['changes' => $validated]
        );

        return response()->json([
            'message' => 'Lead updated successfully',
            'lead' => $lead,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'leads.delete');

        $lead = Lead::findOrFail($id);
        $leadName = $lead->name;
        $leadPhone = $lead->phone;
        $lead->delete();

        AuditLog::log(
            $request->user(),
            'lead.deleted',
            'Lead',
            $id,
            "Deleted lead record #{$id} ({$leadName}, {$leadPhone})",
            ['name' => $leadName, 'phone' => $leadPhone]
        );

        return response()->json([
            'message' => 'Lead record deleted permanently.',
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'leads.delete');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:leads,id',
        ]);

        $count = count($validated['ids']);
        Lead::whereIn('id', $validated['ids'])->delete();

        AuditLog::log(
            $request->user(),
            'lead.bulk_deleted',
            'Lead',
            null,
            "Bulk deleted {$count} lead record(s)",
            ['ids' => $validated['ids']]
        );

        return response()->json([
            'message' => "Successfully deleted {$count} lead record(s).",
        ]);
    }

    public function convertToOrder(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'leads.convert');

        $lead = Lead::findOrFail($id);

        if ($lead->status === 'converted' && $lead->converted_order_id) {
            return response()->json([
                'message' => 'This lead has already been converted to an official order.',
                'order_id' => $lead->converted_order_id,
            ], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'nullable|string|in:credit_card,cash_on_delivery,bank_transfer,paypal',
            'payment_status' => 'nullable|string|in:pending,paid',
            'order_status' => 'nullable|string|in:pending,processing,shipped',
            'shipping_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($request, $lead, $validated) {
            $cartItems = $lead->cart_items ?: [];
            $subtotal = 0;
            $orderItemsData = [];

            if (empty($cartItems)) {
                // If cart items were empty in lead, create with minimum placeholder or lead total
                $subtotal = (float) $lead->total_amount;
            } else {
                foreach ($cartItems as $item) {
                    $productId = $item['product_id'] ?? $item['id'] ?? null;
                    $product = $productId ? Product::with('primaryImage')->find($productId) : null;
                    
                    $unitPrice = isset($item['price']) ? (float) $item['price'] : ($product ? (float) $product->price : 0);
                    $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 1;
                    $itemTotal = $unitPrice * $quantity;
                    $subtotal += $itemTotal;

                    if ($product) {
                        $product->decrement('stock_quantity', $quantity);
                    }

                    $orderItemsData[] = [
                        'product_id' => $product ? $product->id : null,
                        'variant_id' => $item['variant_id'] ?? null,
                        'product_name' => $item['title'] ?? ($product ? $product->name : 'Hardware Item'),
                        'product_sku' => $product ? $product->sku : ('SKU-' . strtoupper(Str::random(6))),
                        'product_image' => $item['image'] ?? ($product?->primaryImage?->image_url),
                        'unit_price' => $unitPrice,
                        'quantity' => $quantity,
                        'total_price' => $itemTotal,
                    ];
                }
            }

            $shipping = (float) ($validated['shipping_amount'] ?? 0);
            $discount = (float) ($validated['discount_amount'] ?? 0);
            $tax = (float) (Setting::isVatEnabled() ? round($subtotal * (Setting::getVatRate() / 100), 2) : 0.00);
            $totalAmount = max(0, $subtotal + $shipping + $tax - $discount);

            $orderNumber = 'ORD-' . strtoupper(Str::random(8));

            $order = Order::create([
                'user_id' => $lead->user_id,
                'order_number' => $orderNumber,
                'customer_name' => $lead->name,
                'customer_email' => $lead->email ?: ($lead->phone . '@lead.guest'),
                'customer_phone' => $lead->phone,
                'shipping_address' => [
                    'full_name' => $lead->name,
                    'address_line1' => $lead->address ?: 'Address not provided in checkout abandonment',
                    'city' => $lead->city ?: 'N/A',
                    'postal_code' => $lead->postal_code ?: '00000',
                    'country' => 'United States',
                    'phone' => $lead->phone,
                ],
                'billing_address' => [
                    'full_name' => $lead->name,
                    'address_line1' => $lead->address ?: 'Address not provided',
                    'city' => $lead->city ?: 'N/A',
                    'postal_code' => $lead->postal_code ?: '00000',
                    'country' => 'United States',
                ],
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'shipping_amount' => $shipping,
                'discount_amount' => $discount,
                'total_amount' => $totalAmount,
                'payment_status' => $validated['payment_status'] ?? 'pending',
                'payment_method' => $validated['payment_method'] ?? 'cash_on_delivery',
                'order_status' => $validated['order_status'] ?? 'processing',
                'notes' => $validated['notes'] ?: "Converted directly from Abandoned Checkout Lead #{$lead->id}.",
                'ip_address' => $request->ip(),
            ]);

            foreach ($orderItemsData as $itemData) {
                $order->items()->create($itemData);
            }

            // Update Lead state to converted
            $lead->update([
                'status' => 'converted',
                'converted_order_id' => $order->id,
            ]);

            AuditLog::log(
                $request->user(),
                'lead.converted_to_order',
                'Lead',
                $lead->id,
                "Converted Abandoned Checkout Lead #{$lead->id} ({$lead->name}) to official Order #{$order->order_number} (Total: \${$order->total_amount})",
                ['lead_id' => $lead->id, 'order_id' => $order->id, 'order_number' => $order->order_number]
            );

            return response()->json([
                'message' => "Lead converted successfully to Order {$order->order_number}!",
                'order' => $order->load('items'),
                'lead' => $lead,
            ]);
        });
    }

    /**
     * Send 1-click WhatsApp cart recovery notification
     */
    public function sendWhatsAppRecovery(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'leads.manage');

        $lead = Lead::findOrFail($id);

        if (empty($lead->phone)) {
            return response()->json([
                'success' => false,
                'message' => 'This lead does not have a valid phone number on record.',
            ], 422);
        }

        $discountCode = $request->input('discount_code');
        $customMessage = $request->input('custom_message');

        if (!empty($customMessage)) {
            $result = \App\Services\WhatsAppService::sendTextMessage($lead->phone, $customMessage);
        } else {
            $result = \App\Services\WhatsAppService::sendAbandonedCartRecovery($lead, $discountCode);
        }

        if ($result['success']) {
            if ($lead->status === 'new') {
                $lead->update(['status' => 'contacted']);
            }

            $timestamp = now()->format('Y-m-d H:i');
            $existingNotes = $lead->notes ? $lead->notes . "\n" : "";
            $lead->update([
                'notes' => $existingNotes . "[{$timestamp}] Sent 1-click WhatsApp abandoned cart recovery reminder."
            ]);

            AuditLog::log(
                $request->user(),
                'lead.whatsapp_recovery_sent',
                'Lead',
                $lead->id,
                "Sent 1-click WhatsApp recovery message to {$lead->phone} for Lead #{$lead->id}",
                ['lead_id' => $lead->id, 'phone' => $lead->phone, 'message_id' => $result['message_id'] ?? null]
            );
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
