<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminBannerController extends Controller
{
    protected array $relations = [
        'promotion:id,name,slug,discount_type,discount_value,badge_text,starts_at,expires_at,status',
        'promotion.codes:id,promotion_id,code,is_active',
        'product:id,name,slug,price',
        'category:id,name,slug',
        'brand:id,name,slug',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'products.view');

        $query = Banner::with($this->relations);

        if ($request->filled('placement')) {
            $placement = $request->input('placement');
            if ($placement === 'primary_hero') {
                $query->whereIn('placement', ['primary_hero', 'hero_slider']);
            } elseif ($placement === 'secondary_hero') {
                $query->whereIn('placement', ['secondary_hero']);
            } elseif ($placement === 'top_strip') {
                $query->whereIn('placement', ['top_strip', 'top_announcement']);
            } elseif ($placement === 'bottom_banner' || $placement === 'middle_promo') {
                $query->whereIn('placement', ['bottom_banner', 'middle_promo', 'discount_carousel']);
            } else {
                $query->where('placement', $placement);
            }
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        // Schedule / Status Filter
        if ($request->filled('status')) {
            $status = $request->input('status');
            $now = now();
            if ($status === 'active') {
                $query->where('is_active', true)
                      ->where(fn($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                      ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now));
            } elseif ($status === 'scheduled') {
                $query->where('is_active', true)->where('starts_at', '>', $now);
            } elseif ($status === 'expired') {
                $query->where('expires_at', '<', $now);
            } elseif ($status === 'draft' || $status === 'paused') {
                $query->where('is_active', false);
            }
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('subtitle', 'like', "%{$search}%")
                  ->orWhere('eyebrow', 'like', "%{$search}%")
                  ->orWhere('badge', 'like', "%{$search}%")
                  ->orWhere('discount_tag', 'like', "%{$search}%")
                  ->orWhere('cta_text', 'like', "%{$search}%");
            });
        }

        $banners = $query->orderBy('sort_order', 'asc')->latest('id')->get();

        return response()->json($banners);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage', 'products.view');

        $banner = Banner::with($this->relations)->findOrFail($id);
        return response()->json($banner);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'eyebrow' => 'nullable|string|max:100',
            'image_url' => 'nullable|string|max:500',
            'mobile_image_url' => 'nullable|string|max:500',
            'alt_text' => 'nullable|string|max:255',
            'cta_text' => 'nullable|string|max:100',
            'cta_link' => 'nullable|string|max:255',
            'destination_type' => 'nullable|string|in:product,category,brand,collection,promotion,page,custom',
            'destination_id' => 'nullable|integer',
            'promotion_id' => 'nullable|exists:promotions,id',
            'placement' => 'nullable|string|max:50',
            'badge' => 'nullable|string|max:50',
            'discount_tag' => 'nullable|string|max:50',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
        ]);

        // Backend Enforcement: If banner is linked to a promotion, enforce authoritative promotion fields
        if (!empty($validated['promotion_id'])) {
            $linkedPromo = \App\Models\Promotion::findOrFail($validated['promotion_id']);
            $validated['destination_type'] = 'promotion';
            $validated['destination_id'] = null;
            $validated['cta_link'] = '/promotions/' . $linkedPromo->slug;
            $validated['discount_tag'] = $linkedPromo->formatted_discount ?: ($linkedPromo->primary_code ? "CODE: {$linkedPromo->primary_code}" : null);
            $validated['starts_at'] = $linkedPromo->starts_at;
            $validated['expires_at'] = $linkedPromo->expires_at;
        }

        $banner = Banner::create([
            'title' => $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'eyebrow' => $validated['eyebrow'] ?? null,
            'image_url' => $validated['image_url'] ?? '',
            'mobile_image_url' => $validated['mobile_image_url'] ?? null,
            'alt_text' => $validated['alt_text'] ?? null,
            'cta_text' => $validated['cta_text'] ?? 'Shop Now',
            'cta_link' => $validated['cta_link'] ?? '/products',
            'destination_type' => $validated['destination_type'] ?? 'custom',
            'destination_id' => $validated['destination_id'] ?? null,
            'promotion_id' => $validated['promotion_id'] ?? null,
            'placement' => $validated['placement'] ?? 'primary_hero',
            'badge' => $validated['badge'] ?? null,
            'discount_tag' => $validated['discount_tag'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
            'starts_at' => $validated['starts_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
            'clicks_count' => 0,
            'impressions_count' => 0,
        ]);

        $banner->load($this->relations);

        AuditLog::log(
            $request->user(),
            'banner.created',
            'Banner',
            $banner->id,
            "Created marketing banner: {$banner->title} ({$banner->placement})",
            null,
            $banner->toArray()
        );

        return response()->json([
            'message' => 'Marketing banner created successfully.',
            'banner' => $banner,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage');

        $banner = Banner::findOrFail($id);
        $oldValues = $banner->toArray();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'eyebrow' => 'nullable|string|max:100',
            'image_url' => 'nullable|string|max:500',
            'mobile_image_url' => 'nullable|string|max:500',
            'alt_text' => 'nullable|string|max:255',
            'cta_text' => 'nullable|string|max:100',
            'cta_link' => 'nullable|string|max:255',
            'destination_type' => 'nullable|string|in:product,category,brand,collection,promotion,page,custom',
            'destination_id' => 'nullable|integer',
            'promotion_id' => 'nullable|exists:promotions,id',
            'placement' => 'nullable|string|max:50',
            'badge' => 'nullable|string|max:50',
            'discount_tag' => 'nullable|string|max:50',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
        ]);

        if (array_key_exists('image_url', $validated) && is_null($validated['image_url'])) {
            $validated['image_url'] = '';
        }

        // Backend Enforcement: If banner is linked to a promotion, enforce authoritative promotion fields
        $targetPromoId = array_key_exists('promotion_id', $validated) ? $validated['promotion_id'] : $banner->promotion_id;
        if (!empty($targetPromoId)) {
            $linkedPromo = \App\Models\Promotion::findOrFail($targetPromoId);
            $validated['destination_type'] = 'promotion';
            $validated['destination_id'] = null;
            $validated['cta_link'] = '/promotions/' . $linkedPromo->slug;
            $validated['discount_tag'] = $linkedPromo->formatted_discount ?: ($linkedPromo->primary_code ? "CODE: {$linkedPromo->primary_code}" : null);
            $validated['starts_at'] = $linkedPromo->starts_at;
            $validated['expires_at'] = $linkedPromo->expires_at;
        }

        $banner->update($validated);
        $banner->load($this->relations);

        AuditLog::log(
            $request->user(),
            'banner.updated',
            'Banner',
            $banner->id,
            "Updated marketing banner: {$banner->title}",
            $oldValues,
            $banner->toArray()
        );

        return response()->json([
            'message' => 'Marketing banner updated successfully.',
            'banner' => $banner,
        ]);
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage');

        $banner = Banner::findOrFail($id);
        $banner->update(['is_active' => !$banner->is_active]);

        return response()->json([
            'message' => "Banner status updated to " . ($banner->is_active ? 'active' : 'inactive') . ".",
            'banner' => $banner->load($this->relations),
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage');

        $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:banners,id',
            'items.*.sort_order' => 'required|integer',
        ]);

        DB::transaction(function () use ($request) {
            foreach ($request->input('items') as $item) {
                Banner::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
            }
        });

        return response()->json([
            'message' => 'Banner display order updated successfully.',
        ]);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage');

        $request->validate([
            'image' => 'required|file|image|mimes:jpeg,png,jpg,webp,gif,avif,jfif|max:10240',
        ], [
            'image.uploaded' => 'The image failed to upload. The file may exceed the server upload limit (' . ini_get('upload_max_filesize') . '). Please choose a smaller image.',
            'image.image' => 'The uploaded file must be a valid image (JPEG, PNG, WebP, GIF, AVIF).',
            'image.mimes' => 'The image must be a file of type: jpeg, png, jpg, webp, gif, avif.',
            'image.max' => 'The image size cannot exceed 10MB.',
            'image.required' => 'An image file is required for upload.',
        ]);

        $file = $request->file('image');
        $ext = $file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'jpg');
        $filename = 'banner_' . Str::random(16) . '_' . time() . '.' . strtolower($ext);
        $path = $file->storeAs('banners', $filename, 'public');
        $fullUrl = url('storage/' . $path);

        return response()->json([
            'message' => 'Banner image uploaded successfully',
            'image_url' => $fullUrl,
            'path' => $path,
            'filename' => $filename,
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage');

        $banner = Banner::findOrFail($id);
        $title = $banner->title;
        $banner->delete();

        AuditLog::log(
            $request->user(),
            'banner.deleted',
            'Banner',
            $id,
            "Deleted marketing banner: {$title}"
        );

        return response()->json([
            'message' => 'Marketing banner deleted successfully.',
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'theme.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:banners,id',
        ]);

        $ids = $validated['ids'];
        $count = count($ids);

        Banner::whereIn('id', $ids)->delete();

        AuditLog::log(
            $request->user(),
            'banner.bulk_deleted',
            'Banner',
            null,
            "Bulk deleted {$count} marketing banners"
        );

        return response()->json([
            'message' => "Successfully deleted {$count} banner(s).",
            'deleted_count' => $count,
        ]);
    }
}

