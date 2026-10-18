<?php

namespace App\Services;

use App\Http\Resources\ProductResource;
use App\Models\HomepageSection;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class HomepageSectionService
{
    const CACHE_KEY = 'homepage_sections_active';
    const CACHE_TTL = 1800; // 30 minutes

    /**
     * Retrieve all active homepage sections with pre-resolved products for display.
     */
    public function getActiveSections(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $sections = HomepageSection::active()
                ->ordered()
                ->with(['category', 'brand'])
                ->get();

            return $sections->map(function (HomepageSection $section) {
                return $this->formatSectionForPublic($section);
            })->values()->toArray();
        });
    }

    /**
     * Clear cached active sections.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Format a section and pre-resolve its products.
     */
    public function formatSectionForPublic(HomepageSection $section): array
    {
        $limit = $section->product_count ?: 14;

        $formatted = [
            'id' => $section->id,
            'title' => $section->title,
            'slug' => $section->slug,
            'subtitle' => $section->subtitle,
            'badge_text' => $section->badge_text,
            'badge_icon' => $section->badge_icon ?: 'Layers',
            'is_active' => $section->is_active,
            'sort_order' => $section->sort_order,
            'product_count' => $limit,
            'view_all_label' => $section->view_all_label ?: 'View All',
            'view_all_url' => $section->view_all_url,
            'has_tabs' => (bool) $section->has_tabs,
            'tabs' => [],
            'products' => [],
        ];

        if ($section->has_tabs && !empty($section->tabs)) {
            $resolvedTabs = [];
            foreach ($section->tabs as $tab) {
                $tabConfig = [
                    'source_type' => $tab['source_type'] ?? 'category',
                    'category_id' => $tab['category_id'] ?? $section->category_id,
                    'brand_id' => $tab['brand_id'] ?? $section->brand_id,
                    'sort_by' => $tab['sort_by'] ?? 'featured',
                    'product_ids' => $tab['product_ids'] ?? [],
                ];

                $products = $this->resolveProducts($tabConfig, $limit);

                $resolvedTabs[] = [
                    'id' => $tab['id'] ?? ('tab_' . md5($tab['name'] ?? 'tab')),
                    'name' => $tab['name'] ?? 'Featured',
                    'source_type' => $tabConfig['source_type'],
                    'category_id' => $tabConfig['category_id'],
                    'brand_id' => $tabConfig['brand_id'],
                    'sort_by' => $tabConfig['sort_by'],
                    'products' => $products,
                ];
            }
            $formatted['tabs'] = $resolvedTabs;
            // Also assign the first tab's products as fallback root products
            $formatted['products'] = !empty($resolvedTabs) ? $resolvedTabs[0]['products'] : [];
        } else {
            $config = [
                'source_type' => $section->source_type ?: 'category',
                'category_id' => $section->category_id,
                'brand_id' => $section->brand_id,
                'sort_by' => $section->sort_by ?: 'featured',
                'product_ids' => $section->product_ids ?: [],
            ];
            $formatted['products'] = $this->resolveProducts($config, $limit);
        }

        return $formatted;
    }

    /**
     * Resolve a list of products based on source criteria.
     */
    public function resolveProducts(array $config, int $limit = 14): array
    {
        $sourceType = $config['source_type'] ?? 'category';
        $sortBy = $config['sort_by'] ?? 'featured';

        // 1. Manual Products Selection
        if ($sourceType === 'manual') {
            $ids = array_filter(array_map('intval', (array) ($config['product_ids'] ?? [])));
            if (empty($ids)) {
                return [];
            }

            $placeholders = implode(',', $ids);
            $results = Product::whereIn('id', $ids)
                ->active()
                ->with(['category', 'subcategory', 'brandRelation', 'primaryImage', 'images', 'variants' => fn($q) => $q->where('is_active', true)->with('image')])
                ->orderByRaw("FIELD(id, {$placeholders})")
                ->take($limit)
                ->get();
            return ProductResource::collection($results)->resolve();
        }

        // 2. Query Builder Base
        $query = Product::with(['category', 'subcategory', 'brandRelation', 'primaryImage', 'images', 'variants' => fn($q) => $q->where('is_active', true)->with('image')])
            ->active();

        // Filter by Category
        if ($sourceType === 'category' && !empty($config['category_id'])) {
            $query->where('category_id', (int) $config['category_id']);
        }

        // Filter by Brand
        if ($sourceType === 'brand' && !empty($config['brand_id'])) {
            $query->where('brand_id', (int) $config['brand_id']);
        }

        // Sorting & Flags
        switch ($sortBy) {
            case 'newest':
                $query->latest();
                break;
            case 'price_asc':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            case 'rating':
                $query->orderBy('rating_average', 'desc')->orderBy('review_count', 'desc');
                break;
            case 'best_selling':
                $query->bestSellers();
                break;
            case 'featured':
            default:
                $query->featured();
                break;
        }

        $results = $query->take($limit)->get();

        // If 'featured' or 'best_selling' returned fewer than 4 products for this category,
        // backfill with other active products from the same category/brand so the showcase is never bare
        if ($results->count() < 4 && ($sortBy === 'featured' || $sortBy === 'best_selling')) {
            $existingIds = $results->pluck('id')->toArray();
            $fillQuery = Product::with(['category', 'subcategory', 'brandRelation', 'primaryImage', 'images', 'variants' => fn($q) => $q->where('is_active', true)->with('image')])
                ->active()
                ->whereNotIn('id', $existingIds);

            if ($sourceType === 'category' && !empty($config['category_id'])) {
                $fillQuery->where('category_id', (int) $config['category_id']);
            } elseif ($sourceType === 'brand' && !empty($config['brand_id'])) {
                $fillQuery->where('brand_id', (int) $config['brand_id']);
            }

            $fillCount = $limit - $results->count();
            if ($fillCount > 0) {
                $backfill = $fillQuery->latest()->take($fillCount)->get();
                $results = $results->concat($backfill);
            }
        }

        return ProductResource::collection($results)->resolve();
    }
}
