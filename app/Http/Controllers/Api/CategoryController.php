<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Cache::remember('api_storefront_categories_tree', 60, function () {
            return Category::withCount('products')
                ->with(['children' => function ($q) {
                    $q->withCount('products')
                      ->orderBy('display_order')
                      ->with(['children' => function ($q2) {
                          $q2->withCount('products')
                             ->orderBy('display_order')
                             ->with(['children' => function ($q3) {
                                 $q3->withCount('products')->orderBy('display_order');
                             }]);
                      }]);
                }])
                ->whereNull('parent_id')
                ->orderBy('display_order')
                ->get();
        });

        return response()->json($categories);
    }

    public function show(string $slug): JsonResponse
    {
        $category = Category::where('slug', $slug)
            ->orWhere('id', $slug)
            ->withCount('products')
            ->with(['products' => fn($q) => $q->active()->with(['primaryImage', 'variants' => fn($vq) => $vq->where('is_active', true)->with('image')])->take(12)])
            ->firstOrFail();

        return response()->json($category);
    }
}
