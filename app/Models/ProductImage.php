<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'image_url',
        'alt_text',
        'color_name',
        'is_primary',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (ProductImage $image) {
            $image->deletePhysicalFile();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'image_id');
    }

    /**
     * Delete the associated physical file from storage if present.
     */
    public function deletePhysicalFile(): bool
    {
        $path = $this->getRelativeStoragePath();
        if ($path && Storage::disk('public')->exists($path)) {
            try {
                return Storage::disk('public')->delete($path);
            } catch (\Throwable $e) {
                Log::warning("Failed to delete product image file: {$path}. Reason: " . $e->getMessage());
            }
        }
        return false;
    }

    /**
     * Resolve the relative storage path on disk('public') from image_url.
     */
    public function getRelativeStoragePath(): ?string
    {
        if (empty($this->image_url)) {
            return null;
        }

        $parsedPath = parse_url($this->image_url, PHP_URL_PATH) ?: $this->image_url;
        $parsedPath = str_replace('\\', '/', $parsedPath);

        if (preg_match('~(?:^|/)storage/(products/[^?#]+)~i', $parsedPath, $matches)) {
            return $matches[1];
        }

        if (preg_match('~^products/[^?#]+~i', $parsedPath, $matches)) {
            return $matches[0];
        }

        return null;
    }
}
