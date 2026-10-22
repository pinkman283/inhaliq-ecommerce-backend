<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminVendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'vendors.view');

        $query = Vendor::withCount(['vendorProducts', 'purchaseOrders'])->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('company_name', 'like', "%{$search}%")
                  ->orWhere('vendor_code', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $vendors = $query->paginate($perPage);

        // Overall Vendor Analytics
        $totalVendors = Vendor::count();
        $activeVendors = Vendor::where('status', 'active')->count();

        return response()->json([
            'vendors' => $vendors,
            'stats' => [
                'total_vendors' => $totalVendors,
                'active_vendors' => $activeVendors,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'vendors.view');

        $vendor = Vendor::with([
            'vendorProducts.product.primaryImage',
            'vendorProducts.variant',
            'purchaseOrders.items',
            'goodsReceipts.items',
            'priceHistories.product',
        ])->findOrFail($id);

        $totalPurchases = (float) $vendor->purchaseOrders()->where('status', '!=', 'cancelled')->sum('total_amount');
        $totalReceivedPOs = $vendor->purchaseOrders()->where('status', 'received')->count();

        return response()->json([
            'vendor' => $vendor,
            'analytics' => [
                'total_purchases_amount' => $totalPurchases,
                'total_pos_count' => $vendor->purchaseOrders()->count(),
                'received_pos_count' => $totalReceivedPOs,
                'products_supplied_count' => $vendor->vendorProducts()->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'vendors.manage');

        $validated = $request->validate([
            'vendor_code' => 'required|string|max:50|unique:vendors,vendor_code',
            'name' => 'required|string|max:255',
            'company_name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'required|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:100',
            'payment_terms' => 'required|string|max:50',
            'notes' => 'nullable|string|max:5000',
            'status' => 'required|in:active,inactive',
        ]);

        $vendor = Vendor::create($validated);

        AuditLog::log(
            $request->user(),
            'vendor.created',
            'Vendor',
            $vendor->id,
            "Created new vendor profile '{$vendor->company_name}' ({$vendor->vendor_code}).",
            null,
            $vendor->toArray()
        );

        return response()->json([
            'message' => 'Vendor created successfully',
            'vendor' => $vendor,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'vendors.manage');

        $vendor = Vendor::findOrFail($id);

        $validated = $request->validate([
            'vendor_code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('vendors')->ignore($vendor->id)],
            'name' => 'sometimes|required|string|max:255',
            'company_name' => 'sometimes|required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'sometimes|required|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:1000',
            'city' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:100',
            'payment_terms' => 'sometimes|required|string|max:50',
            'notes' => 'nullable|string|max:5000',
            'status' => 'sometimes|required|in:active,inactive',
        ]);

        $oldValues = $vendor->toArray();
        $vendor->update($validated);

        AuditLog::log(
            $request->user(),
            'vendor.updated',
            'Vendor',
            $vendor->id,
            "Updated vendor profile '{$vendor->company_name}' ({$vendor->vendor_code}).",
            $oldValues,
            $vendor->toArray()
        );

        return response()->json([
            'message' => 'Vendor updated successfully',
            'vendor' => $vendor,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'vendors.manage');

        $vendor = Vendor::findOrFail($id);
        $name = $vendor->company_name;
        $vendor->delete();

        AuditLog::log(
            $request->user(),
            'vendor.deleted',
            'Vendor',
            $id,
            "Deleted vendor profile '{$name}'.",
            ['id' => $id, 'name' => $name],
            null
        );

        return response()->json(['message' => 'Vendor deleted successfully']);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'vendors.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:vendors,id',
        ]);

        $ids = $validated['ids'];
        $count = count($ids);

        Vendor::whereIn('id', $ids)->delete();

        AuditLog::log(
            $request->user(),
            'vendor.bulk_deleted',
            'Vendor',
            null,
            "Bulk deleted {$count} vendor profiles."
        );

        return response()->json([
            'message' => "Successfully deleted {$count} vendor(s).",
            'deleted_count' => $count,
        ]);
    }
}

