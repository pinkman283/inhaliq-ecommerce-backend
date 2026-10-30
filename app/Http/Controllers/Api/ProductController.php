<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with([
            'category',
            'subcategory',
            'brandRelation',
            'primaryImage',
            'images',
            'variants' => fn($q) => $q->where('is_active', true)->with('image'),
        ])->active();

        // Filter by category slug(s) or ID(s) (including all recursive subcategories)
        if ($request->filled('categories') || $request->filled('category')) {
            $catInput = $request->input('categories', $request->input('category'));
            $catSlugs = is_array($catInput) ? $catInput : explode(',', (string) $catInput);
            $catSlugs = array_values(array_filter(array_map('trim', $catSlugs)));
            if (!empty($catSlugs)) {
                $matchedCategories = \App\Models\Category::whereIn('slug', $catSlugs)
                    ->orWhereIn('id', $catSlugs)
                    ->get();

                $allCategoryIds = [];
                foreach ($matchedCategories as $cat) {
                    $allCategoryIds[] = $cat->id;
                    $descendantIds = $cat->getAllChildrenIds();
                    $allCategoryIds = array_merge($allCategoryIds, $descendantIds);
                }
                $allCategoryIds = array_values(array_unique($allCategoryIds));

                if (!empty($allCategoryIds)) {
                    $query->whereIn('category_id', $allCategoryIds);
                }
            }
        }

        // Filter by brand slug(s) or name(s)
        if ($request->filled('brands') || $request->filled('brand')) {
            $brandInput = $request->input('brands', $request->input('brand'));
            $brandSlugs = is_array($brandInput) ? $brandInput : explode(',', (string) $brandInput);
            $brandSlugs = array_values(array_filter(array_map('trim', $brandSlugs)));
            if (!empty($brandSlugs)) {
                $matchedNames = \App\Models\Brand::whereIn('slug', $brandSlugs)
                    ->orWhereIn('name', $brandSlugs)
                    ->orWhereIn('id', $brandSlugs)
                    ->pluck('name')
                    ->all();

                $allTargets = array_values(array_unique(array_merge($brandSlugs, $matchedNames)));

                $query->where(function ($q) use ($allTargets) {
                    $q->whereIn('brand', $allTargets);
                    foreach ($allTargets as $t) {
                        $q->orWhere('brand', 'like', "%{$t}%");
                    }
                });
            }
        }

        // Search query: optimized with FULLTEXT index + indexed SKU/Name prefix matching
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $cleanedTerm = preg_replace('/[+\-><()~*\"@]+/', ' ', $search);
                    $terms = array_filter(explode(' ', trim((string) $cleanedTerm)));
                    $booleanQuery = !empty($terms) ? implode(' ', array_map(fn($t) => "+{$t}*", $terms)) : $search;

                    $q->whereRaw("MATCH(name, brand, short_description) AGAINST(? IN BOOLEAN MODE)", [$booleanQuery])
                        ->orWhere('name', 'like', "{$search}%")
                        ->orWhere('sku', 'like', "{$search}%")
                        ->orWhereHas('variants', function ($vq) use ($search) {
                            $vq->where('is_active', true)->where('sku', 'like', "{$search}%");
                        });
                });
            }
        }

        // Price range
        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->input('min_price'));
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->input('max_price'));
        }

        // Rating
        if ($request->filled('min_rating')) {
            $query->where('rating_average', '>=', (float) $request->input('min_rating'));
        }

        // Flags
        if ($request->boolean('featured')) {
            $query->featured();
        }
        if ($request->boolean('new_arrivals')) {
            $query->newArrivals();
        }
        if ($request->boolean('best_sellers')) {
            $query->bestSellers();
        }
        if ($request->boolean('in_stock')) {
            $query->where('stock_quantity', '>', 0);
        }
        if ($request->boolean('discounted') || $request->boolean('on_sale') || $request->boolean('discounted_items')) {
            $query->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'price');
        }

        // Sorting
        $sort = $request->input('sort', 'popular');
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            case 'rating':
                $query->orderBy('rating_average', 'desc');
                break;
            case 'newest':
                $query->latest();
                break;
            case 'popular':
            default:
                $query->orderBy('review_count', 'desc')->orderBy('rating_average', 'desc');
                break;
        }

        $perPage = min((int) $request->input('per_page', 12), 50);
        $products = $query->paginate($perPage);

        return response()->json([
            'data' => ProductResource::collection($products->items())->resolve(),
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage(),
            'per_page' => $products->perPage(),
            'total' => $products->total(),
            'from' => $products->firstItem(),
            'to' => $products->lastItem(),
            'first_page_url' => $products->url(1),
            'last_page_url' => $products->url($products->lastPage()),
            'next_page_url' => $products->nextPageUrl(),
            'prev_page_url' => $products->previousPageUrl(),
            'path' => $products->path(),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $product = Product::where('slug', $slug)
            ->orWhere('id', $slug)
            ->with([
                'category',
                'subcategory',
                'brandRelation',
                'primaryImage',
                'images',
                'variants' => fn($q) => $q->where('is_active', true)->with('image'),
                'reviews',
            ])
            ->firstOrFail();

        $reviewsSetting = Setting::where('key', 'reviews_enabled')->first();
        $reviewsEnabled = $reviewsSetting ? filter_var($reviewsSetting->value, FILTER_VALIDATE_BOOLEAN) : true;
        if (!$reviewsEnabled) {
            $product->setRelation('reviews', collect([]));
        }

        // Get related products from the same category
        $relatedProducts = Product::where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->with([
                'category',
                'subcategory',
                'brandRelation',
                'primaryImage',
                'images',
                'variants' => fn($q) => $q->where('is_active', true)->with('image'),
            ])
            ->active()
            ->take(4)
            ->get();

        return response()->json([
            'product' => (new ProductResource($product))->resolve(),
            'related' => ProductResource::collection($relatedProducts)->resolve(),
        ]);
    }

    public function featured(): JsonResponse
    {
        $data = \Illuminate\Support\Facades\Cache::remember('api_storefront_featured_payload', 60, function () {
            $featuredProducts = Product::featured()
                ->with(['category', 'subcategory', 'brandRelation', 'primaryImage', 'images', 'variants' => fn($q) => $q->where('is_active', true)->with('image')])
                ->take(8)
                ->get();

            $newArrivals = Product::newArrivals()
                ->with(['category', 'subcategory', 'brandRelation', 'primaryImage', 'images', 'variants' => fn($q) => $q->where('is_active', true)->with('image')])
                ->take(6)
                ->get();

            $bestSellers = Product::bestSellers()
                ->with(['category', 'subcategory', 'brandRelation', 'primaryImage', 'images', 'variants' => fn($q) => $q->where('is_active', true)->with('image')])
                ->take(6)
                ->get();

            $featuredCategories = Category::whereNull('parent_id')
                ->where('is_featured', true)
                ->withCount('products')
                ->orderBy('display_order')
                ->get();

            if ($featuredCategories->isEmpty()) {
                $featuredCategories = Category::whereNull('parent_id')
                    ->withCount('products')
                    ->orderBy('display_order')
                    ->get();
            }

            return [
                'featured_products' => ProductResource::collection($featuredProducts)->resolve(),
                'new_arrivals' => ProductResource::collection($newArrivals)->resolve(),
                'best_sellers' => ProductResource::collection($bestSellers)->resolve(),
                'featured_categories' => ProductCategoryResource::collection($featuredCategories)->resolve(),
            ];
        });

        return response()->json($data);
    }
}

