<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Strict public allowlist - NEVER expose cost_price, margin, or internal inventory data.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Resolve primary image safely from primaryImage relation or loaded images collection
        $primaryImage = null;
        if ($this->relationLoaded('primaryImage') && $this->primaryImage) {
            $primaryImage = new ProductImageResource($this->primaryImage);
        } elseif ($this->relationLoaded('images') && $this->images->isNotEmpty()) {
            $img = $this->images->firstWhere('is_primary', true) ?? $this->images->first();
            $primaryImage = $img ? new ProductImageResource($img) : null;
        }

        return [
            'id' => (int) $this->id,
            'category_id' => $this->category_id !== null ? (int) $this->category_id : null,
            'subcategory_id' => $this->subcategory_id !== null ? (int) $this->subcategory_id : null,
            'brand_id' => $this->brand_id !== null ? (int) $this->brand_id : null,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'brand' => $this->brand !== null ? (string) $this->brand : null,
            'sku' => $this->sku !== null ? (string) $this->sku : null,
            'short_description' => $this->short_description !== null ? (string) $this->short_description : null,
            'description' => (string) ($this->description ?? ''),
            'price' => (float) ($this->price ?? 0),
            'compare_at_price' => $this->compare_at_price !== null ? (float) $this->compare_at_price : null,
            'stock_quantity' => (int) ($this->stock_quantity ?? 0),
            'is_featured' => (bool) $this->is_featured,
            'is_new_arrival' => (bool) $this->is_new_arrival,
            'is_best_seller' => (bool) $this->is_best_seller,
            'is_active' => (bool) $this->is_active,
            'meta_title' => $this->meta_title !== null ? (string) $this->meta_title : null,
            'meta_description' => $this->meta_description !== null ? (string) $this->meta_description : null,
            'rating_average' => (float) ($this->rating_average ?? 0),
            'review_count' => (int) ($this->review_count ?? 0),
            'tags' => is_array($this->tags) ? $this->tags : [],
            'specifications' => is_array($this->specifications) ? $this->specifications : null,
            'options' => is_array($this->options) ? $this->options : ($this->options ? json_decode((string) $this->options, true) : null),
            'has_options' => (bool) $this->hasOptions(),
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,

            // Image helpers & collections
            'image' => $this->image,
            'primary_image' => $primaryImage,
            'primaryImage' => $primaryImage,
            'images' => $this->whenLoaded('images', fn() => ProductImageResource::collection($this->images)),
            'images_count' => $this->relationLoaded('images') ? $this->images->count() : ($this->images_count ?? null),

            // Relations (Strict allowlisted sub-resources)
            'category' => $this->whenLoaded('category', fn() => $this->category ? new ProductCategoryResource($this->category) : null),
            'subcategory' => $this->whenLoaded('subcategory', fn() => $this->subcategory ? new ProductCategoryResource($this->subcategory) : null),
            'brandRelation' => $this->whenLoaded('brandRelation', fn() => $this->brandRelation ? new ProductBrandResource($this->brandRelation) : null),
            'brand_relation' => $this->whenLoaded('brandRelation', fn() => $this->brandRelation ? new ProductBrandResource($this->brandRelation) : null),
            'variants' => $this->whenLoaded('variants', function () {
                $activeVariants = $this->variants->filter(fn($v) => (bool) ($v->is_active ?? true))->values();
                foreach ($activeVariants as $variant) {
                    if (!$variant->relationLoaded('product')) {
                        $variant->setRelation('product', $this->resource);
                    }
                }
                return ProductVariantResource::collection($activeVariants);
            }),
            'reviews' => $this->whenLoaded('reviews', fn() => ProductReviewResource::collection($this->reviews)),
        ];
    }
}
