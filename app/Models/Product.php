<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'brand_id',
        'name',
        'slug',
        'brand',
        'sku',
        'short_description',
        'description',
        'price',
        'cost_price',
        'compare_at_price',
        'stock_quantity',
        'is_featured',
        'is_new_arrival',
        'is_best_seller',
        'is_active',
        'meta_title',
        'meta_description',
        'rating_average',
        'review_count',
        'tags',
        'specifications',
        'options',
    ];

    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'subcategory_id' => 'integer',
            'price' => 'float',
            'cost_price' => 'float',
            'compare_at_price' => 'float',
            'stock_quantity' => 'integer',
            'is_featured' => 'boolean',
            'is_new_arrival' => 'boolean',
            'is_best_seller' => 'boolean',
            'is_active' => 'boolean',
            'rating_average' => 'float',
            'review_count' => 'integer',
            'tags' => 'array',
            'specifications' => 'array',
            'options' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            // Keep brand string in sync with brand_id
            if ($product->brand_id && empty($product->brand)) {
                $brand = Brand::find($product->brand_id);
                if ($brand) {
                    $product->brand = $brand->name;
                }
            } elseif (!empty($product->brand) && !$product->brand_id) {
                $brand = Brand::firstOrCreate(
                    ['name' => trim($product->brand)],
                    ['slug' => \Illuminate\Support\Str::slug(trim($product->brand))]
                );
                $product->brand_id = $brand->id;
            }
        });

        static::saved(function (Product $product) {
            // Auto-maintain category_brand catalog relationship
            if ($product->category_id && $product->brand_id) {
                \Illuminate\Support\Facades\DB::table('category_brand')->updateOrInsert(
                    ['category_id' => $product->category_id, 'brand_id' => $product->brand_id],
                    ['updated_at' => now(), 'created_at' => now()]
                );
            }
            \Illuminate\Support\Facades\Cache::forget('storefront_header_navigation');
        });

        static::deleting(function (Product $product) {
            foreach ($product->images as $image) {
                $image->delete();
            }
        });

        static::deleted(function () {
            \Illuminate\Support\Facades\Cache::forget('storefront_header_navigation');
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    public function brandRelation(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }


    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('display_order');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    public function getImageAttribute(): ?string
    {
        return $this->primaryImage?->image_url ?? $this->images->first()?->image_url;
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->where('is_approved', true)->latest();
    }

    public function vendorProducts(): HasMany
    {
        return $this->hasMany(VendorProduct::class);
    }

    public function costLayers(): HasMany
    {
        return $this->hasMany(InventoryCostLayer::class)->orderBy('created_at', 'asc');
    }

    public function activeCostLayers(): HasMany
    {
        return $this->hasMany(InventoryCostLayer::class)->where('is_depleted', false)->orderBy('created_at', 'asc');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->latest();
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeNewArrivals(Builder $query): Builder
    {
        return $query->where('is_new_arrival', true);
    }

    public function scopeBestSellers(Builder $query): Builder
    {
        return $query->where('is_best_seller', true);
    }

    public function syncStockFromVariants(): void
    {
        if ($this->variants()->where('is_active', true)->exists()) {
            $totalStock = (int) $this->variants()->where('is_active', true)->sum('stock_quantity');
            $this->updateQuietly(['stock_quantity' => $totalStock]);
        }
    }

    /**
     * Determine whether the product has configured dynamic options.
     */
    public function hasOptions(): bool
    {
        return !empty($this->options) && is_array($this->options) && count($this->options) > 0;
    }

    /**
     * Accessor for $product->has_options
     */
    public function getHasOptionsAttribute(): bool
    {
        return $this->hasOptions();
    }
}
