<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vendor;
use App\Models\VendorPriceHistory;
use App\Models\VendorProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminVendorProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'vendors.view');

        $query = VendorProduct::with(['vendor', 'product.primaryImage', 'variant'])->latest();

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', $request->input('vendor_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        $perPage = (int) $request->input('per_page', 25);
        $vendorProducts = $query->paginate($perPage);

        return response()->json($vendorProducts);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'vendors.manage');

        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'product_id' => 'required|exists:products,id',
            'variant_id' => 'nullable|exists:product_variants,id',
            'vendor_sku' => 'nullable|string|max:100',
            'purchase_price' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'min_order_quantity' => 'nullable|integer|min:1',
            'lead_time_days' => 'nullable|integer|min:0',
            'is_primary' => 'boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        if (!empty($validated['variant_id'])) {
            $variant = \App\Models\ProductVariant::find($validated['variant_id']);
            if (!$variant || (int) $variant->product_id !== (int) $validated['product_id']) {
                return response()->json([
                    'message' => 'The selected variant does not belong to the specified product.',
                ], 422);
            }
        }

        $existing = VendorProduct::where('vendor_id', $validated['vendor_id'])
            ->where('product_id', $validated['product_id'])
            ->where('variant_id', $validated['variant_id'] ?? null)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'This product/variant is already assigned to this vendor. Use update to modify pricing.',
            ], 422);
        }

        $vendorProduct = VendorProduct::create($validated);

        // Record initial price history entry
        VendorPriceHistory::create([
            'vendor_id' => $vendorProduct->vendor_id,
            'product_id' => $vendorProduct->product_id,
            'variant_id' => $vendorProduct->variant_id,
            'price' => $vendorProduct->purchase_price,
            'effective_date' => now()->toDateString(),
            'changed_by_user_id' => $request->user()->id,
            'notes' => $validated['notes'] ?? 'Initial supplier price agreement.',
        ]);

        AuditLog::log(
            $request->user(),
            'vendor_product.created',
            'VendorProduct',
            $vendorProduct->id,
            "Linked product #{$vendorProduct->product_id} to vendor #{$vendorProduct->vendor_id} at purchase price \${$vendorProduct->purchase_price}.",
            null,
            $vendorProduct->toArray()
        );

        return response()->json([
            'message' => 'Product supplier relation added successfully',
            'vendor_product' => $vendorProduct->load(['vendor', 'product', 'variant']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'vendors.manage');

        $vendorProduct = VendorProduct::findOrFail($id);
        $oldPrice = (float) $vendorProduct->purchase_price;

        $validated = $request->validate([
            'vendor_sku' => 'nullable|string|max:100',
            'purchase_price' => 'sometimes|required|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'min_order_quantity' => 'nullable|integer|min:1',
            'lead_time_days' => 'nullable|integer|min:0',
            'is_primary' => 'boolean',
            'status' => 'sometimes|required|in:active,inactive',
            'notes' => 'nullable|string|max:1000',
        ]);

        $newPrice = isset($validated['purchase_price']) ? (float) $validated['purchase_price'] : $oldPrice;

        $vendorProduct->update($validated);

        // If price changed, record historical pricing layer
        if ($newPrice !== $oldPrice) {
            VendorPriceHistory::create([
                'vendor_id' => $vendorProduct->vendor_id,
                'product_id' => $vendorProduct->product_id,
                'variant_id' => $vendorProduct->variant_id,
                'price' => $newPrice,
                'effective_date' => now()->toDateString(),
                'changed_by_user_id' => $request->user()->id,
                'notes' => $validated['notes'] ?? "Purchase price updated from \${$oldPrice} to \${$newPrice}.",
            ]);
        }

        AuditLog::log(
            $request->user(),
            'vendor_product.updated',
            'VendorProduct',
            $vendorProduct->id,
            "Updated supplier product record #{$vendorProduct->id}.",
            ['purchase_price' => $oldPrice],
            $vendorProduct->toArray()
        );

        return response()->json([
            'message' => 'Supplier pricing updated successfully',
            'vendor_product' => $vendorProduct->load(['vendor', 'product', 'variant']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'vendors.manage');

        $vendorProduct = VendorProduct::findOrFail($id);
        $vendorProduct->delete();

        return response()->json(['message' => 'Supplier relation removed successfully']);
    }
}
