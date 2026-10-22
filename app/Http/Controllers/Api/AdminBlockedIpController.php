<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlockedIp;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerRiskService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBlockedIpController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'security.ip_block', 'customers.view', 'customers.manage');

        $query = BlockedIp::with('blockedBy:id,name,email')->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('ip_address', 'like', "%{$search}%")
                  ->orWhere('reason', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $status = $request->input('status');
            if ($status === 'active') {
                $query->where('status', 'active')
                      ->where(function ($q) {
                          $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                      });
            } elseif ($status === 'expired') {
                $query->where(function ($q) {
                    $q->where('status', 'expired')
                      ->orWhere(function ($q2) {
                          $q2->where('status', 'active')->where('expires_at', '<=', now());
                      });
                });
            } else {
                $query->where('status', $status);
            }
        }

        $perPage = (int) $request->input('per_page', 25);
        $paginated = $query->paginate($perPage);

        // Enrich items with related metrics and active evaluation
        $paginated->getCollection()->transform(function ($item) {
            $isActive = $item->isCurrentlyActive();
            $relatedOrdersCount = Order::where('ip_address', $item->ip_address)->count();
            $relatedCustomersCount = Order::where('ip_address', $item->ip_address)->distinct('customer_email')->count('customer_email');

            $data = $item->toArray();
            $data['is_active'] = $isActive;
            $data['related_orders_count'] = $relatedOrdersCount;
            $data['related_customers_count'] = $relatedCustomersCount;
            return $data;
        });

        return response()->json($paginated);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'security.ip_block');

        $validated = $request->validate([
            'ip_address' => 'required|string|max:45',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'duration' => 'required|in:1_hour,24_hours,7_days,30_days,permanent,custom',
            'custom_expires_at' => 'nullable|required_if:duration,custom|date|after:now',
        ]);

        $ip = trim($validated['ip_address']);

        // Check if IP is already in use by multiple legitimate customers and warn if needed
        $coTenantCount = Order::where('ip_address', $ip)->distinct('customer_email')->count('customer_email');

        $blockedIp = CustomerRiskService::blockIp(
            $ip,
            $validated['reason'],
            $validated['notes'] ?? null,
            $request->user(),
            $validated['duration'],
            $validated['custom_expires_at'] ?? null
        );

        return response()->json([
            'message' => "IP Address {$ip} has been blocked.",
            'blocked_ip' => $blockedIp->load('blockedBy:id,name,email'),
            'co_tenant_warning' => $coTenantCount > 1 ? "Note: This IP address has been associated with {$coTenantCount} distinct customer accounts." : null,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'security.ip_block', 'customers.view');

        $blockedIp = BlockedIp::with('blockedBy:id,name,email')->findOrFail($id);
        $isActive = $blockedIp->isCurrentlyActive();

        $relatedOrders = Order::where('ip_address', $blockedIp->ip_address)->latest()->take(20)->get();
        $relatedCustomers = User::whereIn('email', Order::where('ip_address', $blockedIp->ip_address)->pluck('customer_email'))
            ->orWhereIn('id', Order::where('ip_address', $blockedIp->ip_address)->pluck('user_id'))
            ->get();

        $data = $blockedIp->toArray();
        $data['is_active'] = $isActive;
        $data['related_orders'] = $relatedOrders;
        $data['related_customers'] = $relatedCustomers;

        return response()->json($data);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'security.ip_block');

        $blockedIp = BlockedIp::findOrFail($id);
        $ip = $blockedIp->ip_address;

        CustomerRiskService::unblockIp($ip, $request->user(), $request->input('reason', 'Administrative unblock'));

        return response()->json([
            'message' => "IP address {$ip} has been unblocked.",
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'security.ip_block');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:blocked_ips,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $blockedIps = BlockedIp::whereIn('id', $validated['ids'])->get();
        $reason = $validated['reason'] ?? 'Bulk administrative unblock';

        foreach ($blockedIps as $bIp) {
            CustomerRiskService::unblockIp($bIp->ip_address, $request->user(), $reason);
        }

        $count = $blockedIps->count();

        return response()->json([
            'message' => "Successfully unblocked {$count} IP address(es).",
            'unblocked_count' => $count,
        ]);
    }


    public function relatedEntities(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'security.ip_block', 'customers.view');

        $blockedIp = BlockedIp::findOrFail($id);

        $orders = Order::where('ip_address', $blockedIp->ip_address)->latest()->take(50)->get();
        $customerEmails = Order::where('ip_address', $blockedIp->ip_address)->distinct('customer_email')->pluck('customer_email');
        $customers = User::where('role', 'customer')->whereIn('email', $customerEmails)->get();

        return response()->json([
            'blocked_ip' => $blockedIp,
            'orders' => $orders,
            'customers' => $customers,
        ]);
    }
}
