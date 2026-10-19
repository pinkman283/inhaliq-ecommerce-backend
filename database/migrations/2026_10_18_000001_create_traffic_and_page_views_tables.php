<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('traffic_sessions')) {
            Schema::create('traffic_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('session_id', 64)->index();
                $table->string('visitor_id', 64)->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('device_type', 20)->default('desktop')->index(); // desktop, mobile, tablet
                $table->string('channel', 50)->default('Direct')->index(); // Direct, Google Organic, Facebook, etc.
                $table->string('referrer_source', 255)->nullable();
                $table->string('landing_page', 255)->nullable();
                $table->string('utm_source', 100)->nullable();
                $table->string('utm_medium', 100)->nullable();
                $table->string('utm_campaign', 100)->nullable();
                $table->string('domain', 150)->default('www.inhaliq.com');
                $table->timestamps();

                $table->index(['created_at', 'channel']);
            });
        }

        if (!Schema::hasTable('storefront_page_views')) {
            Schema::create('storefront_page_views', function (Blueprint $table) {
                $table->id();
                $table->string('session_id', 64)->index();
                $table->string('visitor_id', 64)->index();
                $table->string('url_path', 255)->index();
                $table->string('page_title', 255);
                $table->string('page_type', 50)->default('Other')->index(); // Home, Category, Subcategory, Child Category, Product, Other
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_page_views');
        Schema::dropIfExists('traffic_sessions');
    }
};
