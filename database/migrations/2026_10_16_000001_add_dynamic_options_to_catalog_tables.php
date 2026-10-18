<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds dynamic options definition to products, multi-attribute / direct price / image / active fields
     * to product_variants, and structured attribute snapshots to order_items.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'options')) {
                $table->json('options')->nullable()->after('specifications')
                    ->comment('Option definitions: [{"name": "Flavor", "values": ["Mint", "Mango"]}, ...]');
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            if (!Schema::hasColumn('product_variants', 'attributes')) {
                $table->json('attributes')->nullable()->after('size')
                    ->comment('Variant attribute key-values: {"Flavor": "Mint", "Capacity": "2ml"}');
            }
            if (!Schema::hasColumn('product_variants', 'price')) {
                $table->decimal('price', 10, 2)->nullable()->after('price_modifier')
                    ->comment('Direct selling price. If null, falls back to product.price + price_modifier');
            }
            if (!Schema::hasColumn('product_variants', 'image_id')) {
                $table->foreignId('image_id')->nullable()->after('barcode')
                    ->constrained('product_images')->nullOnDelete()
                    ->comment('Associated gallery image for this variant');
            }
            if (!Schema::hasColumn('product_variants', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('stock_quantity')
                    ->comment('Soft-deactivation toggle. Historical orders and ledgers remain preserved');
            }

            $indexes = collect(DB::select("SHOW INDEX FROM product_variants"))->pluck('Key_name')->all();
            if (!in_array('idx_product_variants_prod_active', $indexes, true)) {
                $table->index(['product_id', 'is_active'], 'idx_product_variants_prod_active');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'variant_attributes')) {
                $table->json('variant_attributes')->nullable()->after('variant_name')
                    ->comment('Snapshot of structured variant attributes at time of order');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * WARNING: Rolling back this migration after real dynamic-option data has been saved
     * will permanently drop the options, attributes, and direct price columns and destroy
     * dynamic catalog configurations. Only run on development/test databases or when
     * restoring from a verified full database snapshot.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'variant_attributes')) {
                $table->dropColumn('variant_attributes');
            }
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $indexes = collect(DB::select("SHOW INDEX FROM product_variants"))->pluck('Key_name')->all();
            
            // Recreate standalone foreign key index before dropping composite index to satisfy InnoDB constraint
            if (!in_array('product_variants_product_id_foreign', $indexes, true)) {
                $table->index('product_id', 'product_variants_product_id_foreign');
            }
            if (in_array('idx_product_variants_prod_active', $indexes, true)) {
                $table->dropIndex('idx_product_variants_prod_active');
            }

            // Drop foreign key constrained column
            if (Schema::hasColumn('product_variants', 'image_id')) {
                $table->dropConstrainedForeignId('image_id');
            }

            $columnsToDrop = [];
            if (Schema::hasColumn('product_variants', 'attributes')) {
                $columnsToDrop[] = 'attributes';
            }
            if (Schema::hasColumn('product_variants', 'price')) {
                $columnsToDrop[] = 'price';
            }
            if (Schema::hasColumn('product_variants', 'is_active')) {
                $columnsToDrop[] = 'is_active';
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'options')) {
                $table->dropColumn('options');
            }
        });
    }
};
