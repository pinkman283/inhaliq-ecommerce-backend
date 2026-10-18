<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_id',
        'product_id',
        'variant_id',
        'product_name',
        'product_sku',
        'product_image',
        'variant_name',
        'unit_price',
        'cogs_unit_cost',
        'cogs_total',
        'quantity',
        'discount_amount',
        'tax_amount',
        'total_price',
        'net_total',
        'gross_profit',
        'variant_attributes',
    ];

    protected $hidden = [
        'cogs_unit_cost',
        'cogs_total',
        'gross_profit',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'cogs_unit_cost' => 'float',
        'cogs_total' => 'float',
        'quantity' => 'integer',
        'discount_amount' => 'float',
        'tax_amount' => 'float',
        'total_price' => 'float',
        'net_total' => 'float',
        'gross_profit' => 'float',
        'variant_attributes' => 'array',
    ];

    public function getNetUnitPriceAttribute(): float
    {
        if ($this->quantity <= 0) {
            return (float) $this->unit_price;
        }
        $net = ($this->net_total ?? (($this->unit_price * $this->quantity) - ($this->discount_amount ?? 0)));
        return round($net / $this->quantity, 2);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function costLayers(): HasMany
    {
        return $this->hasMany(OrderItemCostLayer::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(OrderReturnItem::class);
    }
}
