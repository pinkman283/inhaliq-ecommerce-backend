<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Core performance indexes on products table
        Schema::table('products', function (Blueprint $table) {
            $existing = collect(DB::select("SHOW INDEX FROM products"))->pluck('Key_name')->unique()->toArray();

            if (!in_array('idx_products_active_cat_price', $existing)) {
                $table->index(['is_active', 'category_id', 'price'], 'idx_products_active_cat_price');
            }
            if (!in_array('idx_products_featured', $existing)) {
                $table->index(['is_active', 'is_featured'], 'idx_products_featured');
            }
            if (!in_array('idx_products_new', $existing)) {
                $table->index(['is_active', 'is_new_arrival'], 'idx_products_new');
            }
            if (!in_array('idx_products_bestseller', $existing)) {
                $table->index(['is_active', 'is_best_seller'], 'idx_products_bestseller');
            }
            if (!in_array('idx_products_popular', $existing)) {
                $table->index(['is_active', 'review_count', 'rating_average'], 'idx_products_popular');
            }
            if (!in_array('idx_products_fulltext', $existing)) {
                $table->fullText(['name', 'brand', 'short_description'], 'idx_products_fulltext');
            }
        });

        // 2. Core performance indexes on orders table
        Schema::table('orders', function (Blueprint $table) {
            $existing = collect(DB::select("SHOW INDEX FROM orders"))->pluck('Key_name')->unique()->toArray();

            if (!in_array('idx_orders_status_created', $existing)) {
                $table->index(['order_status', 'created_at'], 'idx_orders_status_created');
            }
            if (!in_array('idx_orders_payment_status', $existing)) {
                $table->index(['payment_status', 'created_at'], 'idx_orders_payment_status');
            }
            if (!in_array('idx_orders_user_created', $existing)) {
                $table->index(['user_id', 'created_at'], 'idx_orders_user_created');
            }
            if (!in_array('idx_orders_created_at', $existing)) {
                $table->index('created_at', 'idx_orders_created_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $existing = collect(DB::select("SHOW INDEX FROM products"))->pluck('Key_name')->unique()->toArray();

            if (in_array('idx_products_active_cat_price', $existing)) {
                $table->dropIndex('idx_products_active_cat_price');
            }
            if (in_array('idx_products_featured', $existing)) {
                $table->dropIndex('idx_products_featured');
            }
            if (in_array('idx_products_new', $existing)) {
                $table->dropIndex('idx_products_new');
            }
            if (in_array('idx_products_bestseller', $existing)) {
                $table->dropIndex('idx_products_bestseller');
            }
            if (in_array('idx_products_popular', $existing)) {
                $table->dropIndex('idx_products_popular');
            }
            if (in_array('idx_products_fulltext', $existing)) {
                $table->dropIndex('idx_products_fulltext');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            $existing = collect(DB::select("SHOW INDEX FROM orders"))->pluck('Key_name')->unique()->toArray();

            if (in_array('idx_orders_status_created', $existing)) {
                $table->dropIndex('idx_orders_status_created');
            }
            if (in_array('idx_orders_payment_status', $existing)) {
                $table->dropIndex('idx_orders_payment_status');
            }
            if (in_array('idx_orders_user_created', $existing)) {
                $table->dropIndex('idx_orders_user_created');
            }
            if (in_array('idx_orders_created_at', $existing)) {
                $table->dropIndex('idx_orders_created_at');
            }
        });
    }
};

