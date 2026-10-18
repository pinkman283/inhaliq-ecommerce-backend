<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'size',
        'color_name',
        'color_hex',
        'sku',
        'barcode',
        'cost_price',
        'price_modifier',
        'stock_quantity',
        'attributes',
        'variant_attributes',
        'price',
        'image_id',
        'is_active',
    ];

    protected $hidden = [
        'cost_price',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'float',
            'price_modifier' => 'float',
            'stock_quantity' => 'integer',
            'attributes' => 'array',
            'price' => 'float',
            'image_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'image_id');
    }

    /**
     * Scope query to only active variants.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Calculate canonical effective price: direct variant price when non-null;
     * otherwise product base price + variant price_modifier.
     */
    public function getEffectivePriceAttribute(): float
    {
        if ($this->price !== null && $this->price !== '') {
            return round((float) $this->price, 2);
        }
        $base = (float) ($this->product->price ?? 0);
        $modifier = (float) ($this->price_modifier ?? 0);
        return round($base + $modifier, 2);
    }

    /**
     * Helper method returning canonical effective selling price.
     */
    public function effectivePrice(): float
    {
        return $this->effective_price;
    }

    /**
     * Alias accessor for dynamic option attributes (supports both $v->attributes and $v->variant_attributes).
     */
    public function getVariantAttributesAttribute(): ?array
    {
        $val = $this->getAttribute('attributes');
        if (is_string($val)) {
            return json_decode($val, true);
        }
        return is_array($val) ? $val : null;
    }

    /**
     * Alias mutator for dynamic option attributes.
     */
    public function setVariantAttributesAttribute($value): void
    {
        $this->attributes['attributes'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Resolve the variant's public display image URL according to the canonical hierarchy:
     * 1. Explicit image_id referencing the product's own gallery image.
     * 2. Existing legacy color-based image association.
     * 3. Product's primary gallery image.
     * 4. First available gallery image.
     */
    public function resolveImageUrl(): ?string
    {
        $parent = $this->relationLoaded('product') ? $this->product : $this->product()->first();

        // 1. Explicit image_id referencing product's own gallery image
        if ($this->image_id) {
            $candidateImg = null;
            if ($this->relationLoaded('image') && $this->image) {
                $candidateImg = $this->image;
            } elseif ($parent && $parent->relationLoaded('images')) {
                $candidateImg = $parent->images->firstWhere('id', (int) $this->image_id);
            } else {
                $candidateImg = ProductImage::find($this->image_id);
            }

            if ($candidateImg && (!$parent || (int) $candidateImg->product_id === (int) $parent->id)) {
                return $candidateImg->image_url;
            }
        }

        // 2. Existing legacy color-based image association
        $colorName = trim((string) ($this->color_name ?? ''));
        if ($colorName !== '' && $parent) {
            $lowerColor = strtolower($colorName);
            if ($parent->relationLoaded('images')) {
                $colorImg = $parent->images->first(function ($img) use ($lowerColor) {
                    return strtolower(trim((string) ($img->color_name ?? ''))) === $lowerColor;
                });
                if ($colorImg) {
                    return $colorImg->image_url;
                }
            } else {
                $colorImg = $parent->images()->whereRaw('LOWER(TRIM(color_name)) = ?', [$lowerColor])->first();
                if ($colorImg) {
                    return $colorImg->image_url;
                }
            }
        }

        // 3. Product's primary gallery image
        if ($parent) {
            if ($parent->relationLoaded('primaryImage') && $parent->primaryImage) {
                return $parent->primaryImage->image_url;
            }
            if ($parent->relationLoaded('images')) {
                $primary = $parent->images->firstWhere('is_primary', true);
                if ($primary) {
                    return $primary->image_url;
                }
            } else {
                $primary = $parent->primaryImage;
                if ($primary) {
                    return $primary->image_url;
                }
            }
        }

        // 4. First available gallery image
        if ($parent) {
            if ($parent->relationLoaded('images') && $parent->images->isNotEmpty()) {
                return $parent->images->first()->image_url;
            }
            return $parent->image;
        }

        return null;
    }

    /**
     * Accessor for $variant->image_url
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->resolveImageUrl();
    }
}
