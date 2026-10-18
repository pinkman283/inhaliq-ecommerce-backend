<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.view', 'products.manage');

        $query = Product::with(['category', 'subcategory', 'brandRelation', 'primaryImage', 'images', 'variants'])
            ->withCount('images');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('subcategory_id')) {
            $query->where('subcategory_id', $request->input('subcategory_id'));
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->input('brand_id'));
        }

        if ($request->filled('stock_status')) {
            if ($request->input('stock_status') === 'in_stock') {
                $query->where('stock_quantity', '>', 0);
            } elseif ($request->input('stock_status') === 'low_stock') {
                $query->where('stock_quantity', '>', 0)->where('stock_quantity', '<=', 10);
            } elseif ($request->input('stock_status') === 'out_of_stock') {
                $query->where('stock_quantity', '<=', 0);
            }
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        // Media filter: all (default), has_images, no_images
        if ($request->filled('media_filter')) {
            $mediaFilter = $request->input('media_filter');
            if ($mediaFilter === 'has_images') {
                $query->has('images');
            } elseif ($mediaFilter === 'no_images') {
                $query->doesntHave('images');
            }
        }

        // Safe whitelisted server-side sorting
        $sort = $request->input('sort', 'latest');
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            case 'stock_asc':
                $query->orderBy('stock_quantity', 'asc');
                break;
            case 'stock_desc':
                $query->orderBy('stock_quantity', 'desc');
                break;
            case 'name_asc':
                $query->orderBy('name', 'asc');
                break;
            case 'latest':
            default:
                $query->latest();
                break;
        }

        $perPage = (int) $request->input('per_page', 15);
        $products = $query->paginate($perPage);

        $response = $products->toArray();
        $response['stats'] = [
            'total_products' => Product::count(),
            'total_warehouse_units' => (int) Product::sum('stock_quantity'),
            'total_categories' => Category::count(),
            'low_stock_count' => Product::where('stock_quantity', '>', 0)->where('stock_quantity', '<=', 10)->count(),
            'out_of_stock_count' => Product::where('stock_quantity', '<=', 0)->count(),
            'products_without_images_count' => Product::doesntHave('images')->count(),
        ];

        return response()->json($response);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'products.view', 'products.manage');

        $product = Product::with(['category', 'subcategory', 'brandRelation', 'images', 'variants', 'reviews'])->findOrFail($id);
        return response()->json($product);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

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
        $filename = 'prod_' . Str::random(16) . '_' . time() . '.' . strtolower($ext);
        $path = $file->storeAs('products', $filename, 'public');
        $fullUrl = url('storage/' . $path);

        return response()->json([
            'message' => 'Product image uploaded successfully',
            'image_url' => $fullUrl,
            'path' => $path,
            'filename' => $filename,
        ], 201);
    }

    public function deleteUploadedImage(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $validated = $request->validate([
            'image_url' => 'nullable|string',
            'path' => 'nullable|string',
        ]);

        $path = $validated['path'] ?? null;
        if (!$path && !empty($validated['image_url'])) {
            $parsed = parse_url($validated['image_url'], PHP_URL_PATH) ?: $validated['image_url'];
            $parsed = str_replace('\\', '/', $parsed);
            if (preg_match('~(?:^|/)storage/(products/[^?#]+)~i', $parsed, $m)) {
                $path = $m[1];
            } elseif (preg_match('~^products/[^?#]+~i', $parsed, $m)) {
                $path = $m[0];
            }
        }

        if (!$path) {
            return response()->json(['message' => 'Invalid image path or URL provided.'], 422);
        }

        // Security check: path MUST be within products/ and prevent path traversal
        $path = str_replace(['..', "\0"], '', $path);
        if (!str_starts_with($path, 'products/')) {
            return response()->json(['message' => 'Unauthorized path deletion.'], 403);
        }

        // Safety check: ensure file is not attached to an active ProductImage record
        $isReferenced = \App\Models\ProductImage::where('image_url', 'like', "%{$path}%")->exists();
        if ($isReferenced) {
            return response()->json(['message' => 'Cannot delete an active product image via this endpoint.'], 422);
        }

        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
            return response()->json([
                'message' => 'Uploaded image file deleted successfully.',
                'path' => $path,
            ]);
        }

        return response()->json([
            'message' => 'Image file not found on disk or already removed.',
            'path' => $path,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'brand' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'compare_at_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'short_description' => 'nullable|string',
            'description' => 'required|string',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_best_seller' => 'boolean',
            'is_active' => 'boolean',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'image_url' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*.image_url' => 'required_with:images|string',
            'images.*.is_primary' => 'nullable|boolean',
            'images.*.alt_text' => 'nullable|string|max:255',
            'images.*.color_name' => 'nullable|string|max:100',
            'images.*.display_order' => 'nullable|integer',
            'specifications' => 'nullable|array',
            'tags' => 'nullable|array',
            'options' => 'nullable|array',
            'options.*.name' => 'required_with:options|string|max:100',
            'options.*.values' => 'required_with:options|array|min:1',
            'variants' => 'nullable|array',
            'variants.*.name' => 'nullable|string|max:100',
            'variants.*.size' => 'nullable|string|max:50',
            'variants.*.color_name' => 'nullable|string|max:100',
            'variants.*.color_hex' => 'nullable|string|max:50',
            'variants.*.stock_quantity' => 'nullable|integer|min:0',
            'variants.*.price_modifier' => 'nullable|numeric|min:0',
            'variants.*.cost_price' => 'nullable|numeric|min:0',
            'variants.*.barcode' => 'nullable|string|max:100',
            'variants.*.attributes' => 'nullable|array',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.image_id' => 'nullable|integer',
            'variants.*.is_active' => 'nullable|boolean',
        ]);

        $options = $validated['options'] ?? null;
        $variantItems = $validated['variants'] ?? null;

        // Normalize images list
        $imageItems = [];
        if (!empty($validated['images']) && is_array($validated['images'])) {
            $imageItems = array_values(array_filter($validated['images'], function ($img) {
                return !empty($img['image_url']);
            }));
        } elseif (!empty($validated['image_url'])) {
            $imageItems = [
                [
                    'image_url' => $validated['image_url'],
                    'is_primary' => true,
                    'alt_text' => $validated['name'],
                    'display_order' => 0,
                ]
            ];
        }

        // Mandatory check: At least 1 image
        if (empty($imageItems)) {
            return response()->json([
                'message' => 'Validation error: At least 1 product image is mandatory. Please provide an image URL or upload an image from your device.',
                'errors' => ['images' => ['At least 1 product image is mandatory.']]
            ], 422);
        }

        // Limit check: Maximum 5 images
        if (count($imageItems) > 5) {
            return response()->json([
                'message' => 'Validation error: A product cannot have more than 5 images.',
                'errors' => ['images' => ['Maximum limit is 5 images per product.']]
            ], 422);
        }

        // Validate options and variant combinations before persisting anything
        if ($errorResponse = $this->validateDynamicOptionsAndVariants($options, $variantItems)) {
            return $errorResponse;
        }

        DB::beginTransaction();
        try {
            $slug = Str::slug($validated['name']) . '-' . Str::random(4);
            $sku = 'PRD-' . strtoupper(Str::random(6));

            $hasVariants = !empty($variantItems) && is_array($variantItems);

            $product = Product::create([
                'category_id' => $validated['category_id'],
                'subcategory_id' => $validated['subcategory_id'] ?? null,
                'brand_id' => $validated['brand_id'] ?? null,
                'name' => $validated['name'],
                'slug' => $slug,
                'brand' => $validated['brand'] ?? 'AETHER Studio',
                'sku' => $sku,
                'price' => (float) $validated['price'],
                'cost_price' => isset($validated['cost_price']) && $validated['cost_price'] !== null ? (float) $validated['cost_price'] : null,
                'compare_at_price' => isset($validated['compare_at_price']) ? (float) $validated['compare_at_price'] : null,
                'stock_quantity' => $hasVariants ? 0 : (int) $validated['stock_quantity'],
                'short_description' => $validated['short_description'] ?? null,
                'description' => $validated['description'],
                'is_featured' => $validated['is_featured'] ?? false,
                'is_new_arrival' => $validated['is_new_arrival'] ?? true,
                'is_best_seller' => $validated['is_best_seller'] ?? false,
                'is_active' => $validated['is_active'] ?? true,
                'rating_average' => 0.00,
                'review_count' => 0,
                'meta_title' => $validated['meta_title'] ?? null,
                'meta_description' => $validated['meta_description'] ?? null,
                'specifications' => $validated['specifications'] ?? [],
                'tags' => $validated['tags'] ?? [],
                'options' => $options,
            ]);

            $hasPrimary = false;
            foreach ($imageItems as $idx => $img) {
                $isPrimary = !empty($img['is_primary']);
                if ($isPrimary) {
                    if ($hasPrimary) {
                        $isPrimary = false;
                    } else {
                        $hasPrimary = true;
                    }
                }
                $imageItems[$idx]['_calculated_primary'] = $isPrimary;
            }

            if (!$hasPrimary && count($imageItems) > 0) {
                $imageItems[0]['_calculated_primary'] = true;
            }

            $createdImageIds = [];
            foreach ($imageItems as $idx => $img) {
                $createdImg = $product->images()->create([
                    'image_url' => $img['image_url'],
                    'alt_text' => !empty($img['alt_text']) ? $img['alt_text'] : null,
                    'color_name' => !empty($img['color_name']) ? trim((string)$img['color_name']) : null,
                    'is_primary' => $imageItems[$idx]['_calculated_primary'],
                    'display_order' => $img['display_order'] ?? $idx,
                ]);
                $createdImageIds[] = $createdImg->id;
            }

            // Save Variants
            if ($hasVariants) {
                // Check if any variant references an image_id not belonging to this product
                foreach ($variantItems as $v) {
                    if (!empty($v['image_id'])) {
                        $imgId = (int)$v['image_id'];
                        if (!in_array($imgId, $createdImageIds, true)) {
                            DB::rollBack();
                            return response()->json([
                                'message' => "Image ID {$imgId} does not belong to this product.",
                                'errors' => ['variants' => ["Image ID {$imgId} does not belong to this product."]],
                            ], 422);
                        }
                    }
                }

                $createdVariantsCount = 0;
                foreach ($variantItems as $v) {
                    if (empty($v['name']) && empty($v['color_name']) && empty($v['size']) && empty($v['attributes'])) continue;
                    $vStock = (int)($v['stock_quantity'] ?? 0);
                    $createdVariantsCount++;
                    $vName = $v['name'] ?? (!empty($v['attributes']) ? implode(' / ', array_values($v['attributes'])) : ($v['color_name'] ? ($v['color_name'] . (!empty($v['size']) ? ' / ' . $v['size'] : '')) : ($v['size'] ?? 'Standard Option')));
                    $product->variants()->create([
                        'name' => $vName,
                        'size' => $v['size'] ?? null,
                        'color_name' => $v['color_name'] ?? null,
                        'color_hex' => $v['color_hex'] ?? null,
                        'stock_quantity' => $vStock,
                        'price_modifier' => isset($v['price_modifier']) ? (float)$v['price_modifier'] : 0.00,
                        'price' => isset($v['price']) && $v['price'] !== null && $v['price'] !== '' ? (float)$v['price'] : null,
                        'cost_price' => isset($v['cost_price']) && $v['cost_price'] !== null && $v['cost_price'] !== '' ? (float)$v['cost_price'] : null,
                        'barcode' => !empty($v['barcode']) ? trim($v['barcode']) : null,
                        'attributes' => $v['attributes'] ?? null,
                        'image_id' => !empty($v['image_id']) ? (int)$v['image_id'] : null,
                        'is_active' => isset($v['is_active']) ? (bool)$v['is_active'] : true,
                        'sku' => $product->sku . '-' . strtoupper(Str::random(4)),
                    ]);
                }
                if ($createdVariantsCount > 0) {
                    $product->syncStockFromVariants();
                }
            }

            AuditLog::log(
                $request->user(),
                'product.created',
                'Product',
                $product->id,
                "Created hardware product '{$product->name}' (SKU: {$product->sku}) with " . count($imageItems) . " image(s) and " . ($hasVariants ? count($variantItems) : 0) . " variant(s)",
                null,
                $product->toArray()
            );

            DB::commit();

            return response()->json([
                'message' => 'Hardware product created successfully',
                'product' => $product->load(['category', 'primaryImage', 'images', 'variants']),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $product = Product::findOrFail($id);
        $oldValues = $product->toArray();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category_id' => 'sometimes|required|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'brand' => 'nullable|string|max:255',
            'price' => 'sometimes|required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'compare_at_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'sometimes|required|integer|min:0',
            'short_description' => 'nullable|string',
            'description' => 'sometimes|required|string',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_best_seller' => 'boolean',
            'is_active' => 'boolean',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'image_url' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*.id' => 'nullable|integer',
            'images.*.image_url' => 'required_with:images|string',
            'images.*.is_primary' => 'nullable|boolean',
            'images.*.alt_text' => 'nullable|string|max:255',
            'images.*.color_name' => 'nullable|string|max:100',
            'images.*.display_order' => 'nullable|integer',
            'specifications' => 'nullable|array',
            'tags' => 'nullable|array',
            'options' => 'nullable|array',
            'options.*.name' => 'required_with:options|string|max:100',
            'options.*.values' => 'required_with:options|array|min:1',
            'variants' => 'nullable|array',
            'variants.*.id' => 'nullable|integer',
            'variants.*.name' => 'nullable|string|max:100',
            'variants.*.size' => 'nullable|string|max:50',
            'variants.*.color_name' => 'nullable|string|max:100',
            'variants.*.color_hex' => 'nullable|string|max:50',
            'variants.*.stock_quantity' => 'nullable|integer|min:0',
            'variants.*.price_modifier' => 'nullable|numeric|min:0',
            'variants.*.cost_price' => 'nullable|numeric|min:0',
            'variants.*.barcode' => 'nullable|string|max:100',
            'variants.*.attributes' => 'nullable|array',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.image_id' => 'nullable|integer',
            'variants.*.is_active' => 'nullable|boolean',
        ]);

        $hasOptionsInRequest = $request->has('options');
        $effectiveOptions = $hasOptionsInRequest ? $request->input('options') : $product->options;

        $hasVariantsInRequest = $request->has('variants');
        $variantItems = $hasVariantsInRequest ? $request->input('variants') : null;

        // Pre-validate options structure and variant combinations before mutating DB
        if ($errorResponse = $this->validateDynamicOptionsAndVariants($effectiveOptions, $variantItems, $product)) {
            return $errorResponse;
        }

        DB::beginTransaction();
        try {
            // If product has active variants and variants are omitted from this payload, protect parent stock
            if ($product->variants()->where('is_active', true)->exists() && !$hasVariantsInRequest) {
                unset($validated['stock_quantity']);
            }

            if ($hasOptionsInRequest) {
                $product->options = $validated['options'] ?? null;
            }

            $product->fill($validated);
            $wasDirty = $product->isDirty();
            $product->save();

            // Handle image updates if images or image_url were passed
            $availableImageIds = $product->images()->pluck('id')->map(fn($id) => (int)$id)->all();

            if ($request->has('images') || $request->has('image_url')) {
                $imageItems = [];
                if ($request->has('images') && is_array($request->input('images'))) {
                    $imageItems = array_values(array_filter($request->input('images'), function ($img) {
                        return !empty($img['image_url']);
                    }));
                } elseif ($request->filled('image_url')) {
                    $imageItems = [
                        [
                            'image_url' => $request->input('image_url'),
                            'is_primary' => true,
                            'alt_text' => $product->name,
                            'display_order' => 0,
                        ]
                    ];
                }

                // Mandatory check: At least 1 image on modification
                if (empty($imageItems)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Validation error: At least 1 product image is mandatory. Please provide an image URL or upload an image from your device.',
                        'errors' => ['images' => ['At least 1 product image is mandatory.']]
                    ], 422);
                }

                // Limit check: Maximum 5 images
                if (count($imageItems) > 5) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Validation error: A product cannot have more than 5 images.',
                        'errors' => ['images' => ['Maximum limit is 5 images per product.']]
                    ], 422);
                }

                // Reconcile existing images vs submitted images
                $existingImages = $product->images()->get()->keyBy('id');
                $keptImageIds = [];

                $hasPrimary = false;
                foreach ($imageItems as $idx => $img) {
                    $isPrimary = !empty($img['is_primary']);
                    if ($isPrimary) {
                        if ($hasPrimary) {
                            $isPrimary = false;
                        } else {
                            $hasPrimary = true;
                        }
                    }
                    $imageItems[$idx]['_calculated_primary'] = $isPrimary;
                }

                if (!$hasPrimary && count($imageItems) > 0) {
                    $imageItems[0]['_calculated_primary'] = true;
                }

                foreach ($imageItems as $idx => $img) {
                    $imgId = !empty($img['id']) ? (int)$img['id'] : null;
                    $colorName = !empty($img['color_name']) ? trim((string)$img['color_name']) : null;
                    $displayOrder = $img['display_order'] ?? $idx;
                    $altText = !empty($img['alt_text']) ? $img['alt_text'] : null;
                    $imageUrl = $img['image_url'];
                    $isPrimary = $imageItems[$idx]['_calculated_primary'];

                    $matchedExisting = null;
                    if ($imgId && $existingImages->has($imgId)) {
                        $matchedExisting = $existingImages->get($imgId);
                    } else {
                        // Match unkept existing record with the exact same image_url
                        $matchedExisting = $existingImages->first(function ($ex) use ($imageUrl, $keptImageIds) {
                            return $ex->image_url === $imageUrl && !in_array($ex->id, $keptImageIds, true);
                        });
                    }

                    if ($matchedExisting) {
                        // If image URL changed on same ID, delete the replaced physical file
                        if ($matchedExisting->image_url !== $imageUrl) {
                            $matchedExisting->deletePhysicalFile();
                        }

                        $matchedExisting->update([
                            'image_url' => $imageUrl,
                            'alt_text' => $altText,
                            'color_name' => $colorName,
                            'is_primary' => $isPrimary,
                            'display_order' => $displayOrder,
                        ]);
                        $keptImageIds[] = $matchedExisting->id;
                    } else {
                        $newImg = $product->images()->create([
                            'image_url' => $imageUrl,
                            'alt_text' => $altText,
                            'color_name' => $colorName,
                            'is_primary' => $isPrimary,
                            'display_order' => $displayOrder,
                        ]);
                        $keptImageIds[] = $newImg->id;
                    }
                }

                // Remove any existing images that were not kept, triggering physical file deletion via deleting hook
                foreach ($existingImages as $exId => $exImg) {
                    if (!in_array($exId, $keptImageIds, true)) {
                        $exImg->delete();
                    }
                }
                $availableImageIds = $keptImageIds;
                $wasDirty = true;
            }

            // Handle Variants updates with deterministic reconciliation & safe deactivation
            if ($hasVariantsInRequest) {
                if (!is_array($variantItems) || count($variantItems) === 0) {
                    // Mode Transition: Converting to Simple Product!
                    // Safely deactivate all existing variants without physical deletion
                    $product->variants()->update(['is_active' => false]);
                    if (!$hasOptionsInRequest) {
                        $product->options = null;
                    }
                    if (isset($validated['stock_quantity'])) {
                        $product->stock_quantity = (int)$validated['stock_quantity'];
                    }
                    $product->save();
                    $wasDirty = true;
                } else {
                    $existingVariants = $product->variants()->get()->keyBy('id');

                    // 1. Validate submitted variant IDs
                    $submittedIds = [];
                    foreach ($variantItems as $v) {
                        if (!empty($v['id'])) {
                            $sId = (int)$v['id'];
                            if (in_array($sId, $submittedIds, true)) {
                                DB::rollBack();
                                return response()->json([
                                    'message' => "Duplicate variant ID {$sId} submitted.",
                                    'errors' => ['variants' => ["Duplicate variant ID {$sId} submitted."]],
                                ], 422);
                            }
                            $submittedIds[] = $sId;

                            if (!$existingVariants->has($sId)) {
                                DB::rollBack();
                                return response()->json([
                                    'message' => "Variant ID {$sId} does not belong to this product.",
                                    'errors' => ['variants' => ["Variant ID {$sId} does not belong to this product."]],
                                ], 422);
                            }
                        }
                    }

                    // 2. Validate image_id ownership
                    foreach ($variantItems as $v) {
                        if (!empty($v['image_id'])) {
                            $imgId = (int)$v['image_id'];
                            if (!in_array($imgId, $availableImageIds, true)) {
                                DB::rollBack();
                                return response()->json([
                                    'message' => "Image ID {$imgId} does not belong to this product.",
                                    'errors' => ['variants' => ["Image ID {$imgId} does not belong to this product."]],
                                ], 422);
                            }
                        }
                    }

                    $isDynamic = !empty($effectiveOptions) && is_array($effectiveOptions) && count($effectiveOptions) > 0;
                    $keptIds = [];

                    // 3a. Update variants with explicit IDs in-place (PRESERVE ID & SKU)
                    foreach ($variantItems as $v) {
                        if (!empty($v['id'])) {
                            $sId = (int)$v['id'];
                            $existing = $existingVariants->get($sId);
                            $vStock = (int)($v['stock_quantity'] ?? 0);
                            $vName = $v['name'] ?? (!empty($v['attributes']) ? implode(' / ', array_values($v['attributes'])) : ($v['color_name'] ? ($v['color_name'] . (!empty($v['size']) ? ' / ' . $v['size'] : '')) : ($v['size'] ?? $existing->name)));

                            $existing->update([
                                'name' => $vName,
                                'size' => $v['size'] ?? null,
                                'color_name' => $v['color_name'] ?? null,
                                'color_hex' => $v['color_hex'] ?? null,
                                'stock_quantity' => $vStock,
                                'price_modifier' => isset($v['price_modifier']) ? (float)$v['price_modifier'] : 0.00,
                                'price' => isset($v['price']) && $v['price'] !== null && $v['price'] !== '' ? (float)$v['price'] : null,
                                'cost_price' => isset($v['cost_price']) && $v['cost_price'] !== null && $v['cost_price'] !== '' ? (float)$v['cost_price'] : null,
                                'barcode' => !empty($v['barcode']) ? trim($v['barcode']) : null,
                                'attributes' => $v['attributes'] ?? null,
                                'image_id' => !empty($v['image_id']) ? (int)$v['image_id'] : null,
                                'is_active' => isset($v['is_active']) ? (bool)$v['is_active'] : true,
                                'sku' => $existing->sku ?: ($product->sku . '-' . strtoupper(Str::random(4))),
                            ]);
                            $keptIds[] = $existing->id;
                        }
                    }

                    // 3b. Process variants WITHOUT ID: match deterministically or create new
                    $unmatchedExisting = $existingVariants->except($keptIds);

                    foreach ($variantItems as $v) {
                        if (empty($v['id'])) {
                            if (empty($v['name']) && empty($v['color_name']) && empty($v['size']) && empty($v['attributes'])) {
                                continue;
                            }

                            $incomingKey = $this->getCanonicalVariantKey($v, $isDynamic);
                            $matchedExisting = null;

                            if ($incomingKey !== '') {
                                foreach ($unmatchedExisting as $candidate) {
                                    $candidateKey = $this->getCanonicalVariantKey($candidate, $isDynamic);
                                    if ($candidateKey !== '' && $candidateKey === $incomingKey) {
                                        $matchedExisting = $candidate;
                                        break;
                                    }
                                }
                            }

                            $vStock = (int)($v['stock_quantity'] ?? 0);
                            $vName = $v['name'] ?? (!empty($v['attributes']) ? implode(' / ', array_values($v['attributes'])) : ($v['color_name'] ? ($v['color_name'] . (!empty($v['size']) ? ' / ' . $v['size'] : '')) : ($v['size'] ?? 'Standard Option')));
                            $price = isset($v['price']) && $v['price'] !== null && $v['price'] !== '' ? (float)$v['price'] : null;
                            $priceModifier = isset($v['price_modifier']) ? (float)$v['price_modifier'] : 0.00;
                            $costPrice = isset($v['cost_price']) && $v['cost_price'] !== null && $v['cost_price'] !== '' ? (float)$v['cost_price'] : null;
                            $barcode = !empty($v['barcode']) ? trim($v['barcode']) : null;
                            $imageId = !empty($v['image_id']) ? (int)$v['image_id'] : null;
                            $isActive = isset($v['is_active']) ? (bool)$v['is_active'] : true;

                            if ($matchedExisting) {
                                // Deterministic reactivation of matching combination (PRESERVE ID & SKU)
                                $matchedExisting->update([
                                    'name' => $vName,
                                    'size' => $v['size'] ?? null,
                                    'color_name' => $v['color_name'] ?? null,
                                    'color_hex' => $v['color_hex'] ?? null,
                                    'stock_quantity' => $vStock,
                                    'price_modifier' => $priceModifier,
                                    'price' => $price,
                                    'cost_price' => $costPrice,
                                    'barcode' => $barcode,
                                    'attributes' => $v['attributes'] ?? null,
                                    'image_id' => $imageId,
                                    'is_active' => $isActive,
                                    'sku' => $matchedExisting->sku ?: ($product->sku . '-' . strtoupper(Str::random(4))),
                                ]);
                                $keptIds[] = $matchedExisting->id;
                                $unmatchedExisting->forget($matchedExisting->id);
                            } else {
                                // Create new variant row
                                $newVariant = $product->variants()->create([
                                    'name' => $vName,
                                    'size' => $v['size'] ?? null,
                                    'color_name' => $v['color_name'] ?? null,
                                    'color_hex' => $v['color_hex'] ?? null,
                                    'stock_quantity' => $vStock,
                                    'price_modifier' => $priceModifier,
                                    'price' => $price,
                                    'cost_price' => $costPrice,
                                    'barcode' => $barcode,
                                    'attributes' => $v['attributes'] ?? null,
                                    'image_id' => $imageId,
                                    'is_active' => $isActive,
                                    'sku' => $product->sku . '-' . strtoupper(Str::random(4)),
                                ]);
                                $keptIds[] = $newVariant->id;
                            }
                        }
                    }

                    // 4. Safely deactivate omitted variants without physical deletion!
                    $variantsToDeactivate = $existingVariants->except($keptIds);
                    foreach ($variantsToDeactivate as $toDeactivate) {
                        $toDeactivate->update(['is_active' => false]);
                    }

                    // 5. Sync stock from active variants
                    $product->syncStockFromVariants();

                    $wasDirty = true;
                }
            }

            if ($wasDirty) {
                AuditLog::log(
                    $request->user(),
                    'product.updated',
                    'Product',
                    $product->id,
                    "Updated hardware product '{$product->name}' (SKU: {$product->sku})",
                    $oldValues,
                    $product->toArray()
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Product updated successfully',
                'product' => $product->load(['category', 'subcategory', 'brandRelation', 'primaryImage', 'images', 'variants']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $product = Product::findOrFail($id);

        if ($conflicts = $this->getProductHistoricalConflicts($product)) {
            return response()->json([
                'message' => "This product cannot be permanently deleted because it has historical transaction records ({$conflicts['total_conflicts']} record(s) found). Set it inactive instead to protect financial and order history.",
                'code' => 'PRODUCT_DELETE_BLOCKED',
                'conflicts' => $conflicts,
            ], 422);
        }

        $name = $product->name;
        $sku = $product->sku;
        $oldValues = $product->toArray();

        // Individually delete images to trigger physical file cleanup
        foreach ($product->images as $img) {
            $img->delete();
        }

        $product->delete();

        AuditLog::log(
            $request->user(),
            'product.deleted',
            'Product',
            $id,
            "Deleted hardware product '{$name}' (SKU: {$sku})",
            $oldValues,
            null
        );

        return response()->json([
            'message' => "Product '{$name}' deleted successfully",
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:products,id',
        ]);

        $count = 0;
        $deletedNames = [];
        $blocked = [];

        foreach ($validated['ids'] as $id) {
            $product = Product::find($id);
            if (!$product) continue;

            if ($conflicts = $this->getProductHistoricalConflicts($product)) {
                $blocked[] = [
                    'id' => $id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'conflicts' => $conflicts,
                ];
                continue;
            }

            $name = $product->name;
            $sku = $product->sku;
            $oldValues = $product->toArray();

            // Individually delete images to trigger physical file cleanup
            foreach ($product->images as $img) {
                $img->delete();
            }

            $product->delete();
            $deletedNames[] = $name;
            $count++;

            AuditLog::log(
                $request->user(),
                'product.deleted',
                'Product',
                $id,
                "Bulk deleted product '{$name}' (SKU: {$sku})",
                $oldValues,
                null
            );
        }

        if ($count === 0 && !empty($blocked)) {
            return response()->json([
                'message' => "None of the selected products could be deleted because they have historical transaction records.",
                'code' => 'PRODUCT_DELETE_BLOCKED',
                'deleted_count' => 0,
                'blocked_count' => count($blocked),
                'blocked' => $blocked,
            ], 422);
        }

        return response()->json([
            'message' => !empty($blocked)
                ? "Successfully deleted {$count} product(s). " . count($blocked) . " product(s) could not be deleted due to historical records."
                : "Successfully deleted {$count} product(s).",
            'deleted_count' => $count,
            'blocked_count' => count($blocked),
            'blocked' => $blocked,
        ]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $validated = $request->validate([
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer|exists:products,id',
            'operation' => 'required|string|in:activate,deactivate,category,brand,price',
            'category_id' => 'required_if:operation,category|nullable|integer|exists:categories,id',
            'brand_id' => 'required_if:operation,brand|nullable|integer|exists:brands,id',
            'adjustment_type' => 'required_if:operation,price|nullable|string|in:percentage,fixed',
            'value' => 'required_if:operation,price|nullable|numeric',
            'rounding' => 'nullable|string|in:none,round,ceil,floor,round_99',
        ]);

        $productIds = $validated['product_ids'];
        $operation = $validated['operation'];

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $validated, $productIds, $operation) {
            $products = Product::whereIn('id', $productIds)->lockForUpdate()->get();
            $affectedIds = [];
            $details = [];

            switch ($operation) {
                case 'activate':
                    foreach ($products as $product) {
                        $product->update(['is_active' => true]);
                        $affectedIds[] = $product->id;
                    }
                    $details['is_active'] = true;
                    $message = "Successfully activated " . count($affectedIds) . " product(s).";
                    break;

                case 'deactivate':
                    foreach ($products as $product) {
                        $product->update(['is_active' => false]);
                        $affectedIds[] = $product->id;
                    }
                    $details['is_active'] = false;
                    $message = "Successfully deactivated " . count($affectedIds) . " product(s).";
                    break;

                case 'category':
                    $category = \App\Models\Category::findOrFail($validated['category_id']);
                    foreach ($products as $product) {
                        $product->update([
                            'category_id' => $category->id,
                            'subcategory_id' => null, // Reset subcategory to prevent cross-category inconsistency
                        ]);
                        $affectedIds[] = $product->id;
                    }
                    $details['category_id'] = $category->id;
                    $details['category_name'] = $category->name;
                    $message = "Successfully assigned " . count($affectedIds) . " product(s) to category '{$category->name}'.";
                    break;

                case 'brand':
                    $brand = \App\Models\Brand::findOrFail($validated['brand_id']);
                    foreach ($products as $product) {
                        $product->update([
                            'brand_id' => $brand->id,
                            'brand' => $brand->name, // Keep legacy string brand synchronized
                        ]);
                        $affectedIds[] = $product->id;
                    }
                    $details['brand_id'] = $brand->id;
                    $details['brand_name'] = $brand->name;
                    $message = "Successfully assigned " . count($affectedIds) . " product(s) to brand '{$brand->name}'.";
                    break;

                case 'price':
                    $adjustmentType = $validated['adjustment_type'];
                    $adjValue = (float) $validated['value'];
                    $rounding = $validated['rounding'] ?? 'none';

                    // First pass: validate all final prices are non-negative before applying
                    foreach ($products as $product) {
                        $currentPrice = (float) $product->price;
                        if ($adjustmentType === 'percentage') {
                            $calcPrice = $currentPrice + ($currentPrice * $adjValue / 100.0);
                        } else {
                            $calcPrice = $currentPrice + $adjValue;
                        }

                        switch ($rounding) {
                            case 'round':
                                $calcPrice = round($calcPrice);
                                break;
                            case 'ceil':
                                $calcPrice = ceil($calcPrice);
                                break;
                            case 'floor':
                                $calcPrice = floor($calcPrice);
                                break;
                            case 'round_99':
                                $calcPrice = max(0, floor($calcPrice)) + 0.99;
                                break;
                            case 'none':
                            default:
                                $calcPrice = round($calcPrice, 2);
                                break;
                        }

                        $finalPrice = round($calcPrice, 2);

                        if ($finalPrice < 0) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'value' => ["Price adjustment would cause product '{$product->name}' (SKU: {$product->sku}) price to become negative (" . number_format($finalPrice, 2) . "). Negative prices are not permitted."],
                            ]);
                        }
                    }

                    // Second pass: apply price updates
                    foreach ($products as $product) {
                        $currentPrice = (float) $product->price;
                        if ($adjustmentType === 'percentage') {
                            $calcPrice = $currentPrice + ($currentPrice * $adjValue / 100.0);
                        } else {
                            $calcPrice = $currentPrice + $adjValue;
                        }

                        switch ($rounding) {
                            case 'round':
                                $calcPrice = round($calcPrice);
                                break;
                            case 'ceil':
                                $calcPrice = ceil($calcPrice);
                                break;
                            case 'floor':
                                $calcPrice = floor($calcPrice);
                                break;
                            case 'round_99':
                                $calcPrice = max(0, floor($calcPrice)) + 0.99;
                                break;
                            case 'none':
                            default:
                                $calcPrice = round($calcPrice, 2);
                                break;
                        }

                        $product->update(['price' => round($calcPrice, 2)]);
                        $affectedIds[] = $product->id;
                    }

                    $details['adjustment_type'] = $adjustmentType;
                    $details['value'] = $adjValue;
                    $details['rounding'] = $rounding;
                    $message = "Successfully updated pricing for " . count($affectedIds) . " product(s).";
                    break;
            }

            AuditLog::log(
                $request->user(),
                'product.bulk_updated',
                'Product',
                null,
                "Bulk {$operation} applied to " . count($affectedIds) . " product(s)",
                null,
                [
                    'operation' => $operation,
                    'affected_count' => count($affectedIds),
                    'product_ids' => $affectedIds,
                    'details' => $details,
                ]
            );

            return response()->json([
                'message' => $message,
                'operation' => $operation,
                'affected_count' => count($affectedIds),
                'product_ids' => $affectedIds,
            ]);
        });
    }

    /**
     * Check if a product has historical or financial records that prevent safe hard deletion.
     * Returns array of conflict counts or null if clean.
     */
    private function getProductHistoricalConflicts(Product $product): ?array
    {
        $variantIds = $product->variants()->pluck('id')->toArray();

        // 1. Order items referencing this product or any of its variants
        $orderItemsQuery = \Illuminate\Support\Facades\DB::table('order_items')
            ->where('product_id', $product->id);
        if (!empty($variantIds)) {
            $orderItemsQuery->orWhereIn('variant_id', $variantIds);
        }
        $orderItemsCount = $orderItemsQuery->count();

        // 2. Inventory movements referencing this product or variants
        $movementsQuery = \Illuminate\Support\Facades\DB::table('inventory_movements')
            ->where('product_id', $product->id);
        if (!empty($variantIds)) {
            $movementsQuery->orWhereIn('variant_id', $variantIds);
        }
        $inventoryMovementsCount = $movementsQuery->count();

        // 3. Inventory cost layers (FIFO tranches)
        $costLayersQuery = \Illuminate\Support\Facades\DB::table('inventory_cost_layers')
            ->where('product_id', $product->id);
        if (!empty($variantIds)) {
            $costLayersQuery->orWhereIn('variant_id', $variantIds);
        }
        $costLayersCount = $costLayersQuery->count();

        // 4. Return items if table exists
        $returnItemsCount = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('order_return_items')) {
            $returnItemsQuery = \Illuminate\Support\Facades\DB::table('order_return_items')
                ->where('product_id', $product->id);
            if (!empty($variantIds)) {
                $returnItemsQuery->orWhereIn('variant_id', $variantIds);
            }
            $returnItemsCount = $returnItemsQuery->count();
        }

        $totalConflicts = $orderItemsCount + $inventoryMovementsCount + $costLayersCount + $returnItemsCount;

        if ($totalConflicts > 0) {
            return [
                'order_items_count' => $orderItemsCount,
                'inventory_movements_count' => $inventoryMovementsCount,
                'inventory_cost_layers_count' => $costLayersCount,
                'return_items_count' => $returnItemsCount,
                'total_conflicts' => $totalConflicts,
            ];
        }

        return null;
    }

    /**
     * Validate that variants do not contain duplicate color + size combinations.
     * Normalized by trimming whitespace and converting to lowercase.
     * Returns null if valid, or a JsonResponse with HTTP 422 if duplicates are found.
     */
    public function validateVariantCombinations(array $variants): ?JsonResponse
    {
        return $this->validateDynamicOptionsAndVariants(null, $variants);
    }

    /**
     * Compute a deterministic canonical combination key for variant matching.
     */
    protected function getCanonicalVariantKey(array|ProductVariant $variant, bool $isDynamic): string
    {
        if ($isDynamic) {
            $attrs = is_array($variant) ? ($variant['attributes'] ?? []) : ($variant->attributes ?? []);
            if (!is_array($attrs) || empty($attrs)) {
                return '';
            }
            $pairs = [];
            foreach ($attrs as $k => $v) {
                $kTrim = strtolower(trim((string)$k));
                $vTrim = strtolower(trim((string)$v));
                if ($kTrim !== '') {
                    $pairs[$kTrim] = $vTrim;
                }
            }
            ksort($pairs);
            $parts = [];
            foreach ($pairs as $k => $v) {
                $parts[] = "{$k}={$v}";
            }
            return implode('|', $parts);
        }

        $colorName = is_array($variant)
            ? (isset($variant['color_name']) && $variant['color_name'] !== null ? trim((string)$variant['color_name']) : '')
            : (isset($variant->color_name) && $variant->color_name !== null ? trim((string)$variant->color_name) : '');

        $size = is_array($variant)
            ? (isset($variant['size']) && $variant['size'] !== null ? trim((string)$variant['size']) : '')
            : (isset($variant->size) && $variant->size !== null ? trim((string)$variant->size) : '');

        if ($colorName !== '' || $size !== '') {
            return strtolower($colorName) . '|' . strtolower($size);
        }

        $name = is_array($variant) ? ($variant['name'] ?? '') : ($variant->name ?? '');
        return 'name:' . strtolower(trim((string)$name));
    }

    /**
     * Validate dynamic options schema, variant attributes, and combination uniqueness.
     */
    protected function validateDynamicOptionsAndVariants(?array $options, ?array $variants, ?Product $product = null): ?JsonResponse
    {
        $declaredOptions = [];

        // 1. Validate options structure if provided
        if ($options !== null) {
            if (!is_array($options)) {
                return response()->json([
                    'message' => 'Validation error: Options must be an array of option groups.',
                    'errors' => ['options' => ['Options must be an array of option groups.']],
                ], 422);
            }

            $seenOptionNames = [];
            foreach ($options as $optGroup) {
                if (!is_array($optGroup)) {
                    return response()->json([
                        'message' => 'Validation error: Each option group must be an object with name and values.',
                        'errors' => ['options' => ['Each option group must be an object with name and values.']],
                    ], 422);
                }

                $name = isset($optGroup['name']) && is_string($optGroup['name']) ? trim($optGroup['name']) : '';
                if ($name === '') {
                    return response()->json([
                        'message' => 'Validation error: Each option group must have a non-empty name.',
                        'errors' => ['options' => ['Each option group must have a non-empty name.']],
                    ], 422);
                }

                $lowerName = strtolower($name);
                if (isset($seenOptionNames[$lowerName])) {
                    return response()->json([
                        'message' => "Duplicate option group name '{$name}'. Option group names must be unique.",
                        'errors' => ['options' => ["Duplicate option group name '{$name}'. Option group names must be unique."]],
                    ], 422);
                }
                $seenOptionNames[$lowerName] = true;

                if (!isset($optGroup['values']) || !is_array($optGroup['values']) || count($optGroup['values']) === 0) {
                    return response()->json([
                        'message' => "Option group '{$name}' must contain at least one value.",
                        'errors' => ['options' => ["Option group '{$name}' must contain at least one value."]],
                    ], 422);
                }

                $seenValues = [];
                $groupValues = [];
                foreach ($optGroup['values'] as $val) {
                    if (!is_string($val) && !is_numeric($val)) {
                        return response()->json([
                            'message' => "Option values for '{$name}' must be non-empty strings.",
                            'errors' => ['options' => ["Option values for '{$name}' must be non-empty strings."]],
                        ], 422);
                    }
                    $valStr = trim((string)$val);
                    if ($valStr === '') {
                        return response()->json([
                            'message' => "Option values for '{$name}' must be non-empty strings.",
                            'errors' => ['options' => ["Option values for '{$name}' must be non-empty strings."]],
                        ], 422);
                    }
                    $lowerVal = strtolower($valStr);
                    if (isset($seenValues[$lowerVal])) {
                        return response()->json([
                            'message' => "Duplicate value '{$valStr}' in option group '{$name}'. Values within an option group must be unique.",
                            'errors' => ['options' => ["Duplicate value '{$valStr}' in option group '{$name}'. Values within an option group must be unique."]],
                        ], 422);
                    }
                    $seenValues[$lowerVal] = true;
                    $groupValues[] = $valStr;
                }

                $declaredOptions[$lowerName] = [
                    'name' => $name,
                    'values' => $groupValues,
                    'values_lower' => $seenValues,
                ];
            }
        }

        // 2. Validate variants if provided
        if ($variants !== null && is_array($variants) && count($variants) > 0) {
            $isDynamic = !empty($declaredOptions);
            $seenCombinations = [];

            foreach ($variants as $idx => $v) {
                if (!is_array($v)) {
                    return response()->json([
                        'message' => 'Validation error: Each variant must be an object.',
                        'errors' => ['variants' => ['Each variant must be an object.']],
                    ], 422);
                }

                if ($isDynamic) {
                    $vName = $v['name'] ?? 'Variant #' . ($idx + 1);
                    $attributes = $v['attributes'] ?? null;

                    if (!is_array($attributes)) {
                        return response()->json([
                            'message' => "Variant '{$vName}' is missing attributes for dynamic options.",
                            'errors' => ['variants' => ["Variant '{$vName}' is missing attributes for dynamic options."]],
                        ], 422);
                    }

                    // Map attributes by lowercased trimmed key
                    $attrMap = [];
                    foreach ($attributes as $attrKey => $attrVal) {
                        $lowerKey = strtolower(trim((string)$attrKey));
                        $attrMap[$lowerKey] = [
                            'original_key' => $attrKey,
                            'value' => $attrVal,
                        ];
                    }

                    // Check that EVERY declared option group is present with a valid value
                    foreach ($declaredOptions as $lowerOptName => $optMeta) {
                        if (!isset($attrMap[$lowerOptName])) {
                            return response()->json([
                                'message' => "Missing attribute '{$optMeta['name']}' for variant '{$vName}'. Every declared option group must have a value.",
                                'errors' => ['variants' => ["Missing attribute '{$optMeta['name']}' for variant '{$vName}'. Every declared option group must have a value."]],
                            ], 422);
                        }

                        $rawVal = $attrMap[$lowerOptName]['value'];
                        if (!is_string($rawVal) && !is_numeric($rawVal)) {
                            return response()->json([
                                'message' => "Attribute '{$optMeta['name']}' must be a non-empty string for variant '{$vName}'.",
                                'errors' => ['variants' => ["Attribute '{$optMeta['name']}' must be a non-empty string for variant '{$vName}'."]],
                            ], 422);
                        }

                        $valStr = trim((string)$rawVal);
                        if ($valStr === '') {
                            return response()->json([
                                'message' => "Missing attribute '{$optMeta['name']}' for variant '{$vName}'. Every declared option group must have a value.",
                                'errors' => ['variants' => ["Missing attribute '{$optMeta['name']}' for variant '{$vName}'. Every declared option group must have a value."]],
                            ], 422);
                        }

                        if (!isset($optMeta['values_lower'][strtolower($valStr)])) {
                            return response()->json([
                                'message' => "Invalid value '{$valStr}' for option group '{$optMeta['name']}'. Allowed values: " . implode(', ', $optMeta['values']) . ".",
                                'errors' => ['variants' => ["Invalid value '{$valStr}' for option group '{$optMeta['name']}'. Allowed values: " . implode(', ', $optMeta['values']) . "."]],
                            ], 422);
                        }
                    }

                    // Check for extra/undeclared attributes
                    foreach ($attrMap as $lowerKey => $attrInfo) {
                        if (!isset($declaredOptions[$lowerKey])) {
                            return response()->json([
                                'message' => "Unknown attribute '{$attrInfo['original_key']}' for variant '{$vName}'. Only declared option groups are allowed.",
                                'errors' => ['variants' => ["Unknown attribute '{$attrInfo['original_key']}' for variant '{$vName}'. Only declared option groups are allowed."]],
                            ], 422);
                        }
                    }

                    // Check for duplicate attribute combinations in payload
                    $canonicalKey = $this->getCanonicalVariantKey($v, true);
                    if (isset($seenCombinations[$canonicalKey])) {
                        return response()->json([
                            'message' => 'Duplicate variant combination submitted.',
                            'errors' => ['variants' => ["Duplicate combination '{$canonicalKey}' submitted."]],
                        ], 422);
                    }
                    $seenCombinations[$canonicalKey] = true;
                } else {
                    // Legacy color + size duplicate checking
                    $colorName = isset($v['color_name']) && $v['color_name'] !== null ? trim((string)$v['color_name']) : '';
                    $size = isset($v['size']) && $v['size'] !== null ? trim((string)$v['size']) : '';

                    if ($colorName !== '' || $size !== '') {
                        $key = strtolower($colorName) . '|' . strtolower($size);
                        if (isset($seenCombinations[$key])) {
                            $display = $colorName !== '' && $size !== ''
                                ? "{$colorName} / {$size}"
                                : ($colorName !== '' ? $colorName : $size);
                            return response()->json([
                                'message' => 'Duplicate variant combination submitted.',
                                'errors' => [
                                    'variants' => ["Duplicate combination '{$display}' submitted."],
                                ],
                            ], 422);
                        }
                        $seenCombinations[$key] = true;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Export products to a streamed CSV file.
     * Respects server-side filtering, sorting, UTF-8 BOM, and formula injection protection.
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $this->checkPermission($request, 'products.view', 'products.manage');

        $validator = Validator::make($request->all(), [
            'search' => 'nullable|string|max:255',
            'category_id' => 'nullable|integer|exists:categories,id',
            'category' => 'nullable|integer|exists:categories,id',
            'subcategory_id' => 'nullable|integer|exists:categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'brand' => 'nullable|integer|exists:brands,id',
            'stock_status' => 'nullable|string|in:all,in_stock,low_stock,out_of_stock',
            'stock' => 'nullable|string|in:all,low,in_stock,low_stock,out_of_stock,out',
            'status' => 'nullable|string|in:all,active,inactive',
            'is_active' => 'nullable',
            'media_filter' => 'nullable|string|in:all,has_images,no_images',
            'badge' => 'nullable|string|in:all,featured,new_arrival,best_seller',
            'sort' => 'nullable|string|in:latest,price_asc,price_desc,stock_asc,stock_desc,name_asc,id_asc,id_desc',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid export parameters.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = Product::with(['category:id,name', 'brandRelation:id,name', 'primaryImage', 'images', 'variants']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        $categoryId = $request->input('category_id', $request->input('category'));
        if (!empty($categoryId) && is_numeric($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        if ($request->filled('subcategory_id')) {
            $query->where('subcategory_id', $request->input('subcategory_id'));
        }

        $brandId = $request->input('brand_id', $request->input('brand'));
        if (!empty($brandId) && is_numeric($brandId)) {
            $query->where('brand_id', $brandId);
        }

        $stock = $request->input('stock_status', $request->input('stock'));
        if (!empty($stock) && $stock !== 'all') {
            if ($stock === 'in_stock') {
                $query->where('stock_quantity', '>', 0);
            } elseif ($stock === 'low_stock' || $stock === 'low') {
                $query->where('stock_quantity', '>', 0)->where('stock_quantity', '<=', 10);
            } elseif ($stock === 'out_of_stock' || $stock === 'out') {
                $query->where('stock_quantity', '<=', 0);
            }
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $status = $request->input('status');
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        } elseif ($request->filled('is_active') && $request->input('is_active') !== 'all') {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('media_filter') && $request->input('media_filter') !== 'all') {
            $mediaFilter = $request->input('media_filter');
            if ($mediaFilter === 'has_images') {
                $query->has('images');
            } elseif ($mediaFilter === 'no_images') {
                $query->doesntHave('images');
            }
        }

        if ($request->filled('badge') && $request->input('badge') !== 'all') {
            $badge = $request->input('badge');
            if ($badge === 'featured') {
                $query->where('is_featured', true);
            } elseif ($badge === 'new_arrival') {
                $query->where('is_new_arrival', true);
            } elseif ($badge === 'best_seller') {
                $query->where('is_best_seller', true);
            }
        }

        $sort = $request->input('sort', 'id_asc');
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('price', 'asc')->orderBy('id', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc')->orderBy('id', 'desc');
                break;
            case 'stock_asc':
                $query->orderBy('stock_quantity', 'asc')->orderBy('id', 'asc');
                break;
            case 'stock_desc':
                $query->orderBy('stock_quantity', 'desc')->orderBy('id', 'desc');
                break;
            case 'name_asc':
                $query->orderBy('name', 'asc')->orderBy('id', 'asc');
                break;
            case 'id_desc':
            case 'latest':
                $query->orderBy('id', 'desc');
                break;
            case 'id_asc':
            default:
                $query->orderBy('id', 'asc');
                break;
        }

        $filename = 'products-export-' . now()->format('Y-m-d-H-i') . '.csv';

        $includeOptions = filter_var($request->input('include_options', $request->input('options', false)), FILTER_VALIDATE_BOOLEAN);

        return response()->streamDownload(function () use ($query, $includeOptions) {
            $handle = fopen('php://output', 'w');

            // Write UTF-8 BOM for Excel / spreadsheet compatibility
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            $headers = [
                'ID',
                'SKU',
                'Name',
                'Slug',
                'Description',
                'Short Description',
                'Category',
                'Brand',
                'Price',
                'Compare At Price',
                'Cost Price',
                'Stock Quantity',
                'Is Active',
                'Is Featured',
                'Is New Arrival',
                'Is Best Seller',
                'Primary Image URL',
                'Additional Image URLs',
                'Variant Summary',
                'Created At',
                'Updated At',
            ];

            if ($includeOptions) {
                $headers[] = 'Options';
            }

            fputcsv($handle, $headers);

            $query->chunk(100, function ($products) use ($handle, $includeOptions) {
                foreach ($products as $product) {
                    $primaryImg = $product->primaryImage
                        ?? $product->images->where('is_primary', true)->first()
                        ?? $product->images->first();

                    $primaryUrl = $primaryImg?->image_url ?? '';

                    $additionalImages = $product->images
                        ->reject(fn($img) => $primaryImg && $img->id === $primaryImg->id)
                        ->values()
                        ->map(fn($img) => [
                            'url' => $img->image_url,
                            'color_name' => $img->color_name,
                            'alt_text' => $img->alt_text,
                            'display_order' => (int) $img->display_order,
                            'is_primary' => (bool) $img->is_primary,
                        ]);

                    $additionalImagesJson = $additionalImages->isNotEmpty()
                        ? json_encode($additionalImages->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : '';

                    $variants = $product->variants->map(function ($v) {
                        $item = [
                            'sku' => $v->sku,
                            'name' => $v->name,
                            'color_name' => $v->color_name,
                            'color_hex' => $v->color_hex,
                            'size' => $v->size,
                            'price_modifier' => (float) ($v->price_modifier ?? 0),
                            'cost_price' => $v->cost_price !== null ? (float) $v->cost_price : null,
                            'stock_quantity' => (int) ($v->stock_quantity ?? 0),
                            'barcode' => $v->barcode,
                            'price' => $v->price !== null ? (float) $v->price : null,
                            'is_active' => (bool) $v->is_active,
                            'attributes' => $v->variant_attributes ?: null,
                        ];

                        if ($v->id) {
                            $item['id'] = $v->id;
                        }

                        if ($v->image_id && $v->image) {
                            $item['image_id'] = $v->image_id;
                            $item['image_url'] = $v->image->image_url;
                        }

                        return $item;
                    });

                    $variantSummaryJson = $variants->isNotEmpty()
                        ? json_encode($variants->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        : '';

                    $row = [
                        $product->id,
                        $this->sanitizeCsvField($product->sku),
                        $this->sanitizeCsvField($product->name),
                        $this->sanitizeCsvField($product->slug),
                        $this->sanitizeCsvField($product->description ?? ''),
                        $this->sanitizeCsvField($product->short_description ?? ''),
                        $this->sanitizeCsvField($product->category?->name ?? ''),
                        $this->sanitizeCsvField($product->brandRelation?->name ?? $product->brand ?? ''),
                        number_format((float) $product->price, 2, '.', ''),
                        $product->compare_at_price !== null ? number_format((float) $product->compare_at_price, 2, '.', '') : '',
                        $product->cost_price !== null ? number_format((float) $product->cost_price, 2, '.', '') : '',
                        $product->stock_quantity,
                        $product->is_active ? 1 : 0,
                        $product->is_featured ? 1 : 0,
                        $product->is_new_arrival ? 1 : 0,
                        $product->is_best_seller ? 1 : 0,
                        $this->sanitizeCsvField($primaryUrl),
                        $additionalImagesJson,
                        $variantSummaryJson,
                        $product->created_at ? $product->created_at->format('Y-m-d H:i:s') : '',
                        $product->updated_at ? $product->updated_at->format('Y-m-d H:i:s') : '',
                    ];

                    if ($includeOptions) {
                        $row[] = !empty($product->options)
                            ? json_encode($product->options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                            : '';
                    }

                    fputcsv($handle, $row);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Sanitize a value against spreadsheet formula injection (CSV / DDE Injection).
     * If a non-numeric string begins with =, +, -, @, \t, or \r, prefix with a single quote (').
     */
    protected function sanitizeCsvField(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (is_numeric($value)) {
            return $value;
        }

        if (preg_match('/^[=\+\-@\t\r]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Validate an uploaded catalog CSV file (Dry-Run).
     * Does NOT mutate the database.
     */
    public function importValidate(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        if (!$request->hasFile('file')) {
            return response()->json([
                'message' => 'Validation error: A CSV file is required.',
                'errors' => ['file' => ['A CSV file is required.']],
            ], 422);
        }

        $file = $request->file('file');
        if (!$file->isValid()) {
            return response()->json([
                'message' => 'Uploaded file is corrupted or failed to upload.',
                'errors' => ['file' => ['Uploaded file is corrupted or invalid.']],
            ], 422);
        }

        try {
            $service = new ProductImportService();
            $result = $service->validateUpload($file, $request->user(), [
                'full_replacement' => $request->boolean('full_replacement'),
            ]);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => ['file' => [$e->getMessage()]],
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred during CSV validation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Commit a validated catalog CSV import transactionally.
     */
    public function importCommit(Request $request): JsonResponse
    {
        $this->checkPermission($request, 'products.manage');

        $validated = $request->validate([
            'import_token' => 'required|string|size:40',
        ]);

        try {
            $service = new ProductImportService();
            $result = $service->commitImport(
                $validated['import_token'],
                $request->user(),
                $request->boolean('full_replacement')
            );
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An unexpected error occurred during import commit: ' . $e->getMessage(),
            ], 500);
        }
    }
}

