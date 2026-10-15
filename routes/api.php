<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminAccountingController;
use App\Http\Controllers\Api\AdminAuditLogController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminBannerController;
use App\Http\Controllers\Api\AdminBlockedIpController;
use App\Http\Controllers\Api\AdminBlogController;
use App\Http\Controllers\Api\AdminBrandController;
use App\Http\Controllers\Api\AdminBrandLogoController;
use App\Http\Controllers\Api\AdminCategoryController;
use App\Http\Controllers\Api\AdminColorController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminCouponController;
use App\Http\Controllers\Api\AdminCustomerController;
use App\Http\Controllers\Api\AdminExpenseController;
use App\Http\Controllers\Api\AdminFinanceController;
use App\Http\Controllers\Api\AdminGoodsReceiptController;
use App\Http\Controllers\Api\AdminIntegrationController;
use App\Http\Controllers\Api\AdminInventoryController;
use App\Http\Controllers\Api\AdminInventoryMovementController;
use App\Http\Controllers\Api\AdminInventoryValuationController;
use App\Http\Controllers\Api\AdminLeadController;
use App\Http\Controllers\Api\AdminNavigationController;
use App\Http\Controllers\Api\AdminOnlineStoreController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminPosController;
use App\Http\Controllers\Api\AdminPosRegisterController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AdminPromotionController;
use App\Http\Controllers\Api\AdminHomepageSectionController;
use App\Http\Controllers\Api\AdminPurchaseOrderController;
use App\Http\Controllers\Api\AdminReportController;
use App\Http\Controllers\Api\AdminReturnController;
use App\Http\Controllers\Api\AdminCourierSettlementController;
use App\Http\Controllers\Api\AdminReviewController;
use App\Http\Controllers\Api\AdminSalesController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AdminStaffController;
use App\Http\Controllers\Api\AdminThemeController;
use App\Http\Controllers\Api\AdminVendorController;
use App\Http\Controllers\Api\AdminVendorProductController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CartValidationController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CustomerCartController;
use App\Http\Controllers\Api\CustomerWishlistController;
use App\Http\Controllers\Api\HomepageSectionController;
use App\Http\Controllers\Api\LeadCaptureController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\PublicBannerController;
use App\Http\Controllers\Api\PublicNavigationController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ThemeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ==========================================
// 1. SYSTEM HEALTH & MONITORING
// ==========================================
Route::get('/health', function () {
    $status = 'ok';
    $checks = [];

    // 1. Database Check
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $checks['database'] = ['status' => 'healthy', 'message' => 'Connected'];
    } catch (\Throwable $e) {
        $status = 'degraded';
        \Illuminate\Support\Facades\Log::error('Health check DB error: ' . $e->getMessage());
        $checks['database'] = ['status' => 'unhealthy', 'message' => 'Database connection unavailable'];
    }

    // 2. Cache Check
    try {
        \Illuminate\Support\Facades\Cache::put('health_check', true, 10);
        $checks['cache'] = ['status' => 'healthy', 'message' => 'Read/write functional'];
    } catch (\Throwable $e) {
        $status = 'degraded';
        \Illuminate\Support\Facades\Log::error('Health check Cache error: ' . $e->getMessage());
        $checks['cache'] = ['status' => 'unhealthy', 'message' => 'Cache store unavailable'];
    }

    // 3. Storage Writable Check
    $storageWritable = is_writable(storage_path('framework/views'));
    $checks['storage'] = [
        'status' => $storageWritable ? 'healthy' : 'unhealthy',
        'message' => $storageWritable ? 'Storage directories writable' : 'Storage directory not writable',
    ];
    if (!$storageWritable) {
        $status = 'degraded';
    }

    $statusCode = $status === 'ok' ? 200 : 503;

    return response()->json([
        'status' => $status,
        'timestamp' => now()->toIso8601String(),
        'environment' => config('app.env'),
        'checks' => $checks,
    ], $statusCode);
});

// ==========================================
// 2. PUBLIC STOREFRONT ENDPOINTS
// ==========================================
Route::get('/featured', [ProductController::class, 'featured']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{slug}', [CategoryController::class, 'show']);
Route::get('/brands', [BrandController::class, 'index']);
Route::get('/brands/{slug}', [BrandController::class, 'show']);
Route::post('/cart/validate', [CartValidationController::class, 'validateCart']);
Route::post('/coupons/validate', [CouponController::class, 'validateCoupon'])->middleware('sliding-throttle:coupon-validation');
Route::post('/promotions/evaluate', [PromotionController::class, 'evaluate']);
Route::get('/promotions/claimable', [PromotionController::class, 'claimable']);
Route::get('/promotions/storefront', [PromotionController::class, 'storefrontPromotions']);
Route::get('/promotions/campaign/{slug}', [PromotionController::class, 'campaignDetails']);
Route::get('/orders/track/{orderNumber}', [OrderController::class, 'track']);
Route::get('/shipping-zones', [OrderController::class, 'shippingZones']);
Route::get('/payment-methods', [OrderController::class, 'paymentMethods']);
Route::post('/webhooks/courier/{provider}', [\App\Http\Controllers\Api\CourierWebhookController::class, 'handle']);
Route::get('/orders/{orderNumber}', [OrderController::class, 'show']);
Route::post('/orders', [OrderController::class, 'store'])->middleware('sliding-throttle:order-checkout'); // Guest / Customer Checkout
Route::post('/leads/capture', [LeadCaptureController::class, 'capture'])->middleware('sliding-throttle:leads-capture'); // Storefront Checkout Abandonment Capture
Route::get('/theme-settings', [ThemeController::class, 'publicSettings']);
Route::get('/sitemap.xml', [AdminSettingsController::class, 'sitemapXml']);
Route::get('/integrations/tracking', [AdminSettingsController::class, 'publicTrackingScripts']);
Route::get('/system/redis-config', [AdminIntegrationController::class, 'getRedisConfig']);
Route::post('/products/{productId}/reviews', [ReviewController::class, 'store'])->middleware('sliding-throttle:customer-reviews');

// Dynamic Storefront Header Navigation
Route::get('/navigation/header', [PublicNavigationController::class, 'header']);

// Dynamic Storefront Homepage Promotional Banners
Route::get('/homepage/banners', [PublicBannerController::class, 'homepage']);
Route::post('/banners/{id}/click', [PublicBannerController::class, 'trackClick']);

// Dynamic Storefront Homepage Showcase Sections
Route::get('/homepage/sections', [HomepageSectionController::class, 'index']);
Route::get('/homepage/sections/{id}/tab-products', [HomepageSectionController::class, 'tabProducts']);

// Public Storefront Colors, Blog & CMS Pages
Route::get('/colors', function () {
    return response()->json(\App\Models\Color::where('status', 'active')->orderBy('name')->get());
});
Route::get('/blog/posts', function (\Illuminate\Http\Request $request) {
    $q = \App\Models\BlogPost::where('status', 'published')->with(['category', 'tags', 'author:id,name']);
    if ($request->filled('category')) {
        $q->whereHas('category', fn($c) => $c->where('slug', $request->input('category')));
    }
    if ($request->filled('tag')) {
        $q->whereHas('tags', fn($t) => $t->where('slug', $request->input('tag')));
    }
    return response()->json($q->latest('published_at')->paginate(12));
});
Route::get('/blog/posts/{slug}', function (string $slug) {
    $post = \App\Models\BlogPost::where('slug', $slug)
        ->where('status', 'published')
        ->with(['category', 'tags', 'author:id,name', 'comments' => fn($c) => $c->where('status', 'approved')->latest()])
        ->firstOrFail();
    $post->increment('views_count');
    return response()->json($post);
});
Route::post('/blog/posts/{id}/comments', function (\Illuminate\Http\Request $request, int $id) {
    $validated = $request->validate([
        'author_name' => 'required|string|max:100',
        'author_email' => 'required|email|max:150',
        'comment' => 'required|string|max:2000',
    ]);
    $comment = \App\Models\BlogComment::create([
        'post_id' => $id,
        'author_name' => $validated['author_name'],
        'author_email' => $validated['author_email'],
        'comment' => $validated['comment'],
        'is_approved' => false,
    ]);
    return response()->json(['message' => 'Comment submitted for moderation.', 'comment' => $comment], 201);
})->middleware('sliding-throttle:customer-reviews');
Route::get('/pages/{slug}', function (string $slug) {
    return response()->json(\App\Models\CmsPage::where('slug', $slug)->where('is_active', true)->firstOrFail());
});
Route::get('/store-navigation', function () {
    $columns = \App\Models\FooterColumn::where('is_active', true)
        ->orderBy('sort_order')
        ->with(['links' => function ($query) {
            $query->where('is_active', true)->orderBy('sort_order');
        }])
        ->get();

    return response()->json([
        'footer_columns' => $columns,
        'footer_links' => \App\Models\FooterLink::where('is_active', true)->orderBy('sort_order')->get()->groupBy('column_group'),
        'social_links' => \App\Models\SocialLink::where('is_active', true)->orderBy('sort_order')->get(),
    ]);
});
Route::get('/banners', function (\Illuminate\Http\Request $request) {
    $q = \App\Models\Banner::where('is_active', true);
    if ($request->filled('placement')) {
        $q->where('placement', $request->input('placement'));
    }
    return response()->json($q->orderBy('sort_order')->get());
});
Route::post('/banners/{id}/click', function (int $id) {
    \App\Models\Banner::where('id', $id)->increment('clicks_count');
    return response()->json(['success' => true]);
});

// ==========================================
// 2. CUSTOMER AUTHENTICATION
// ==========================================
Route::post('/auth/register/request', [AuthController::class, 'registerRequest'])->middleware('sliding-throttle:auth-register');
Route::post('/auth/register/verify', [AuthController::class, 'registerVerify'])->middleware('sliding-throttle:auth-register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('sliding-throttle:auth-customer-login');
Route::post('/auth/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('sliding-throttle:auth-register');
Route::post('/auth/password/verify-reset-otp', [AuthController::class, 'verifyResetOtp'])->middleware('sliding-throttle:auth-register');
Route::post('/auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('sliding-throttle:auth-register');

// Customer Authenticated Routes (Strictly require customer:access token ability)
Route::middleware(['auth:sanctum', 'ability:customer:access'])->group(function () {
    Route::get('/auth/profile', [AuthController::class, 'profile']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Customer Orders & Addresses
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders/{orderNumber}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{orderNumber}/return', [OrderController::class, 'requestReturn']);
    Route::get('/addresses', [AddressController::class, 'index']);
    Route::post('/addresses', [AddressController::class, 'store']);
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy']);

    // Customer Promotions, Coupons & Store Credit Wallet
    Route::post('/promotions/claim', [PromotionController::class, 'claim']);
    Route::get('/promotions/my-coupons', [PromotionController::class, 'myCoupons']);
    Route::get('/promotions/store-credit', [PromotionController::class, 'storeCredit']);

    // Customer Server Cart Persistence & Synchronization
    Route::get('/customer/cart', [CustomerCartController::class, 'getCart']);
    Route::post('/customer/cart/sync', [CustomerCartController::class, 'syncCart']);
    Route::post('/customer/cart/merge', [CustomerCartController::class, 'mergeCart']);
    Route::delete('/customer/cart', [CustomerCartController::class, 'clearCart']);

    // Customer Server Wishlist Persistence & Synchronization
    Route::get('/customer/wishlist', [CustomerWishlistController::class, 'getWishlist']);
    Route::post('/customer/wishlist/toggle', [CustomerWishlistController::class, 'toggleWishlist']);
    Route::post('/customer/wishlist/merge', [CustomerWishlistController::class, 'mergeWishlist']);
    Route::delete('/customer/wishlist', [CustomerWishlistController::class, 'clearWishlist']);
});

// ==========================================
// 3. DEDICATED ADMIN AUTHENTICATION
// ==========================================
// Protected by named composite rate limiter auth-admin-login
Route::middleware('sliding-throttle:auth-admin-login')->group(function () {
    Route::post('/admin/auth/login', [AdminAuthController::class, 'login']);
    Route::post('/admin/auth/activate', [AdminAuthController::class, 'activate']);
});

// ==========================================
// 4. ENTERPRISE ADMIN SECURE BOUNDARY
// ==========================================
// Protected by Sanctum token authentication, admin:access ability AND EnsureAdmin role verification
Route::middleware(['auth:sanctum', 'ability:admin:access', 'admin'])->prefix('admin')->group(function () {
    // Admin Session
    Route::get('/auth/me', [AdminAuthController::class, 'me']);
    Route::put('/auth/profile', [AdminAuthController::class, 'updateProfile']);
    Route::post('/auth/logout', [AdminAuthController::class, 'logout']);
    Route::post('/auth/invite', [AdminAuthController::class, 'invite']);

    // Executive Overview & Analytics
    Route::get('/analytics', [AdminController::class, 'analytics']);

    // Product Management
    Route::get('/products', [AdminProductController::class, 'index']);
    Route::get('/products/export', [AdminProductController::class, 'export']);
    Route::post('/products/import/validate', [AdminProductController::class, 'importValidate']);
    Route::post('/products/import/commit', [AdminProductController::class, 'importCommit']);
    Route::get('/products/{id}', [AdminProductController::class, 'show']);
    Route::post('/products/upload-image', [AdminProductController::class, 'uploadImage']);
    Route::post('/products/delete-uncommitted-image', [AdminProductController::class, 'deleteUploadedImage']);
    Route::post('/products', [AdminProductController::class, 'store']);
    Route::post('/products/bulk-delete', [AdminProductController::class, 'bulkDestroy']);
    Route::post('/products/bulk-update', [AdminProductController::class, 'bulkUpdate']);
    Route::put('/products/{id}', [AdminProductController::class, 'update']);
    Route::delete('/products/{id}', [AdminProductController::class, 'destroy']);

    // Category Management
    Route::get('/categories', [AdminCategoryController::class, 'index']);
    Route::post('/categories', [AdminCategoryController::class, 'store']);
    Route::post('/categories/upload-image', [AdminCategoryController::class, 'uploadImage']);
    Route::post('/categories/bulk-delete', [AdminCategoryController::class, 'bulkDestroy']);
    Route::put('/categories/{id}', [AdminCategoryController::class, 'update']);
    Route::delete('/categories/{id}', [AdminCategoryController::class, 'destroy']);

    // Brand Management
    Route::get('/brands', [AdminBrandController::class, 'index']);
    Route::get('/brands/{id}', [AdminBrandController::class, 'show']);
    Route::post('/brands/upload-logo', [AdminBrandController::class, 'uploadLogo']);
    Route::post('/brands', [AdminBrandController::class, 'store']);
    Route::post('/brands/bulk-delete', [AdminBrandController::class, 'bulkDestroy']);
    Route::put('/brands/{id}', [AdminBrandController::class, 'update']);
    Route::delete('/brands/{id}', [AdminBrandController::class, 'destroy']);

    // Color Swatch Management
    Route::get('/colors', [AdminColorController::class, 'index']);
    Route::get('/colors/{id}', [AdminColorController::class, 'show']);
    Route::post('/colors', [AdminColorController::class, 'store']);
    Route::put('/colors/{id}', [AdminColorController::class, 'update']);
    Route::patch('/colors/{id}/status', [AdminColorController::class, 'toggleStatus']);
    Route::delete('/colors/{id}', [AdminColorController::class, 'destroy']);

    // Order Lifecycle & Fulfillment
    Route::get('/orders/counts', [AdminOrderController::class, 'counts']);
    Route::get('/orders', [AdminOrderController::class, 'index']);
    Route::get('/orders/{id}', [AdminOrderController::class, 'show']);
    Route::post('/orders', [AdminOrderController::class, 'store']);
    Route::post('/orders/bulk-delete', [AdminOrderController::class, 'bulkDestroy']);
    Route::put('/orders/{id}', [AdminOrderController::class, 'update']);
    Route::delete('/orders/{id}', [AdminOrderController::class, 'destroy']);
    Route::patch('/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
    Route::post('/orders/{id}/payments', [AdminOrderController::class, 'recordPayment']);
    Route::post('/orders/{id}/refund', [AdminOrderController::class, 'refund'])->middleware('sliding-throttle:sensitive-admin-action');

    // Courier Logistics & Consignment Management
    Route::get('/orders/{id}/timeline', [AdminOrderController::class, 'getTimeline']);
    Route::get('/orders/courier/pathao/cities', [AdminOrderController::class, 'getPathaoCities']);
    Route::get('/orders/courier/pathao/zones/{cityId}', [AdminOrderController::class, 'getPathaoZones']);
    Route::get('/orders/courier/pathao/areas/{zoneId}', [AdminOrderController::class, 'getPathaoAreas']);
    Route::get('/orders/{id}/courier-options', [AdminOrderController::class, 'getCourierOptions']);
    Route::post('/orders/{id}/courier/calculate-price', [AdminOrderController::class, 'calculateCourierPrice']);
    Route::post('/orders/{id}/shipments', [AdminOrderController::class, 'bookShipment']);
    Route::get('/orders/{id}/shipments/{shipmentId}/track', [AdminOrderController::class, 'trackShipment']);
    Route::post('/orders/{id}/shipments/{shipmentId}/cancel', [AdminOrderController::class, 'cancelShipment']);
    Route::get('/orders/{id}/shipments/{shipmentId}/label', [AdminOrderController::class, 'printShippingLabel']);

    // Returns & RTO Management
    Route::get('/returns', [AdminReturnController::class, 'index']);
    Route::get('/returns/{id}', [AdminReturnController::class, 'show']);
    Route::post('/returns', [AdminReturnController::class, 'store']);
    Route::post('/returns/{id}/receive', [AdminReturnController::class, 'receive']);
    Route::post('/returns/{id}/qc', [AdminReturnController::class, 'qc']);
    Route::post('/returns/{id}/refund', [AdminReturnController::class, 'refund'])->middleware('sliding-throttle:sensitive-admin-action');
    Route::delete('/returns/{id}', [AdminReturnController::class, 'destroy']);
    Route::post('/returns/bulk-delete', [AdminReturnController::class, 'bulkDestroy']);

    // Commercial Sales History & Invoices
    Route::get('/sales', [AdminSalesController::class, 'index']);
    Route::get('/sales/invoice/{id}', [AdminSalesController::class, 'invoice']);
    Route::get('/sales/export', [AdminSalesController::class, 'export']);

    // Lead Management & Abandoned Checkout Recovery
    Route::get('/leads', [AdminLeadController::class, 'index']);
    Route::get('/leads/{id}', [AdminLeadController::class, 'show']);
    Route::post('/leads/bulk-delete', [AdminLeadController::class, 'bulkDestroy']);
    Route::put('/leads/{id}', [AdminLeadController::class, 'update']);
    Route::delete('/leads/{id}', [AdminLeadController::class, 'destroy']);
    Route::post('/leads/{id}/convert-to-order', [AdminLeadController::class, 'convertToOrder']);

    // Customer Management & Risk Intelligence
    Route::get('/customers', [AdminCustomerController::class, 'index']);
    Route::get('/customers/{id}', [AdminCustomerController::class, 'show']);
    Route::get('/customers/{id}/timeline', [AdminCustomerController::class, 'timeline']);
    Route::get('/customers/{id}/ip-history', [AdminCustomerController::class, 'ipHistory']);
    Route::post('/customers', [AdminCustomerController::class, 'store']);
    Route::post('/customers/bulk-delete', [AdminCustomerController::class, 'bulkDestroy']);
    Route::put('/customers/{id}', [AdminCustomerController::class, 'update']);
    Route::delete('/customers/{id}', [AdminCustomerController::class, 'destroy']);
    Route::patch('/customers/{id}/status', [AdminCustomerController::class, 'toggleStatus']);
    Route::post('/customers/{id}/suspend', [AdminCustomerController::class, 'suspend']);
    Route::post('/customers/{id}/reactivate', [AdminCustomerController::class, 'reactivate']);
    Route::post('/customers/{id}/block', [AdminCustomerController::class, 'block']);
    Route::post('/customers/{id}/unblock', [AdminCustomerController::class, 'unblock']);
    Route::post('/customers/{id}/review', [AdminCustomerController::class, 'setReview']);
    Route::put('/customers/{id}/notes', [AdminCustomerController::class, 'updateNotes']);

    // Security & IP Abuse Registry
    Route::get('/blocked-ips', [AdminBlockedIpController::class, 'index']);
    Route::get('/blocked-ips/{id}', [AdminBlockedIpController::class, 'show']);
    Route::get('/blocked-ips/{id}/related', [AdminBlockedIpController::class, 'relatedEntities']);
    Route::post('/blocked-ips', [AdminBlockedIpController::class, 'store'])->middleware('sliding-throttle:sensitive-admin-action');
    Route::delete('/blocked-ips/{id}', [AdminBlockedIpController::class, 'destroy'])->middleware('sliding-throttle:sensitive-admin-action');

    // Enterprise Promotions & Discounts Suite
    Route::prefix('promotions')->group(function () {
        Route::get('/analytics', [AdminPromotionController::class, 'analytics']);
        Route::get('/codes', [AdminPromotionController::class, 'codes']);
        Route::get('/claims', [AdminPromotionController::class, 'claims']);
        Route::get('/redemptions', [AdminPromotionController::class, 'redemptions']);
        Route::post('/customer-rewards', [AdminPromotionController::class, 'issueCustomerReward']);
        Route::get('/store-credit', [AdminPromotionController::class, 'storeCreditAccounts']);
        Route::get('/store-credit/accounts', [AdminPromotionController::class, 'storeCreditAccounts']);
        Route::post('/store-credit/adjust', [AdminPromotionController::class, 'adjustStoreCredit']);
        Route::get('/store-credit/ledger', [AdminPromotionController::class, 'storeCreditLedger']);
        Route::get('/store-credit/{userId}/ledger', [AdminPromotionController::class, 'storeCreditLedger'])->whereNumber('userId');
        Route::get('/', [AdminPromotionController::class, 'index']);
        Route::post('/', [AdminPromotionController::class, 'store']);
        Route::get('/{id}', [AdminPromotionController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [AdminPromotionController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [AdminPromotionController::class, 'destroy'])->whereNumber('id');
        Route::patch('/{id}/status', [AdminPromotionController::class, 'toggleStatus'])->whereNumber('id');
        Route::post('/{id}/codes', [AdminPromotionController::class, 'generateCodes'])->whereNumber('id');
        Route::post('/{id}/generate-codes', [AdminPromotionController::class, 'generateCodes'])->whereNumber('id');
        Route::post('/upload-image', [AdminPromotionController::class, 'uploadImage']);
    });

    // Legacy Coupons (Preserved for 100% backward compatibility)
    Route::get('/coupons', [AdminCouponController::class, 'index']);
    Route::post('/coupons', [AdminCouponController::class, 'store']);
    Route::post('/coupons/bulk-delete', [AdminCouponController::class, 'bulkDestroy']);
    Route::put('/coupons/{id}', [AdminCouponController::class, 'update']);
    Route::delete('/coupons/{id}', [AdminCouponController::class, 'destroy']);

    // Real-Time Inventory & Stock Adjustments
    Route::get('/inventory', [AdminInventoryController::class, 'index']);
    Route::post('/inventory/{id}/adjust', [AdminInventoryController::class, 'adjustStock']);

    // Inventory Costing, Valuation & Auditable Movement Ledger
    Route::get('/inventory-valuation', [AdminInventoryValuationController::class, 'index']);
    Route::post('/inventory-valuation/adjust', [AdminInventoryValuationController::class, 'adjust']);
    Route::get('/inventory-ledger', [AdminInventoryMovementController::class, 'index']);

    // Vendor Management & Procurement
    Route::get('/vendors', [AdminVendorController::class, 'index']);
    Route::get('/vendors/{id}', [AdminVendorController::class, 'show']);
    Route::post('/vendors', [AdminVendorController::class, 'store']);
    Route::put('/vendors/{id}', [AdminVendorController::class, 'update']);
    Route::delete('/vendors/{id}', [AdminVendorController::class, 'destroy']);

    Route::get('/vendor-products', [AdminVendorProductController::class, 'index']);
    Route::post('/vendor-products', [AdminVendorProductController::class, 'store']);
    Route::put('/vendor-products/{id}', [AdminVendorProductController::class, 'update']);
    Route::delete('/vendor-products/{id}', [AdminVendorProductController::class, 'destroy']);

    // Purchase Orders & Procurement Flow
    Route::get('/purchase-orders', [AdminPurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/{id}', [AdminPurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders', [AdminPurchaseOrderController::class, 'store']);
    Route::post('/purchase-orders/{id}/submit', [AdminPurchaseOrderController::class, 'submit']);
    Route::post('/purchase-orders/{id}/approve', [AdminPurchaseOrderController::class, 'approve']);
    Route::post('/purchase-orders/{id}/cancel', [AdminPurchaseOrderController::class, 'cancel']);

    // Goods Receipts (GRN) & Physical Receiving
    Route::get('/goods-receipts', [AdminGoodsReceiptController::class, 'index']);
    Route::get('/goods-receipts/{id}', [AdminGoodsReceiptController::class, 'show']);
    Route::post('/goods-receipts', [AdminGoodsReceiptController::class, 'store']);

    // POS (Point of Sale) & Register Sessions
    Route::get('/pos/registers', [AdminPosRegisterController::class, 'index']);
    Route::get('/pos/registers/current-session', [AdminPosRegisterController::class, 'currentSession']);
    Route::post('/pos/registers/{id}/open-session', [AdminPosRegisterController::class, 'openSession']);
    Route::post('/pos/registers/{id}/close-session', [AdminPosRegisterController::class, 'closeSession']);
    Route::post('/pos/registers/{id}/cash-movement', [AdminPosRegisterController::class, 'cashMovement']);

    Route::get('/pos/products', [AdminPosController::class, 'products']);
    Route::post('/pos/checkout', [AdminPosController::class, 'checkout']);

    // Operating Expenses & Categories
    Route::get('/expenses', [AdminExpenseController::class, 'index']);
    Route::post('/expenses', [AdminExpenseController::class, 'store']);
    Route::put('/expenses/{id}', [AdminExpenseController::class, 'update']);
    Route::delete('/expenses/{id}', [AdminExpenseController::class, 'destroy']);
    Route::get('/expense-categories', [AdminExpenseController::class, 'categories']);
    Route::post('/expense-categories', [AdminExpenseController::class, 'storeCategory']);

    // Financial Engine, P&L, Profitability & Drill-Downs
    Route::get('/finance/summary', [AdminFinanceController::class, 'summary']);
    Route::get('/finance/product-profitability', [AdminFinanceController::class, 'productProfitability']);
    Route::get('/finance/vendor-analytics', [AdminFinanceController::class, 'vendorAnalytics']);
    Route::get('/finance/drilldown', [AdminFinanceController::class, 'drilldown']);
    Route::get('/finance/export', [AdminFinanceController::class, 'export']);

    // ==========================================
    // Accounting & Double-Entry Financial Engine
    // ==========================================
    Route::prefix('accounting')->group(function () {
        Route::get('/overview', [AdminAccountingController::class, 'overview']);
        Route::get('/accounts', [AdminAccountingController::class, 'accounts']);
        Route::post('/accounts', [AdminAccountingController::class, 'storeAccount']);
        Route::get('/ledger', [AdminAccountingController::class, 'ledger']);
        Route::post('/ledger/journal-entry', [AdminAccountingController::class, 'createJournalEntry']);
        Route::get('/receivables', [AdminAccountingController::class, 'receivables']);
        Route::post('/receivables/payment', [AdminAccountingController::class, 'recordCustomerPayment']);
        Route::get('/payables', [AdminAccountingController::class, 'payables']);
        Route::post('/payables/payment', [AdminAccountingController::class, 'recordSupplierPayment']);
        Route::get('/banking', [AdminAccountingController::class, 'banking']);
        Route::post('/banking/transfer', [AdminAccountingController::class, 'transfer']);
        Route::get('/reports', [AdminAccountingController::class, 'reports']);
        Route::get('/export', [AdminAccountingController::class, 'export']);

        // Courier Settlement Statements & Variance Reconciliation
        Route::get('/settlements', [AdminCourierSettlementController::class, 'index']);
        Route::post('/settlements/import-csv', [AdminCourierSettlementController::class, 'importCsv']);
        Route::get('/settlements/{id}', [AdminCourierSettlementController::class, 'show']);
        Route::post('/settlements', [AdminCourierSettlementController::class, 'store']);
        Route::post('/settlements/{id}/reconcile', [AdminCourierSettlementController::class, 'reconcile']);
    });

    // Review Moderation & Management
    Route::get('/reviews/summary', [AdminReviewController::class, 'summary']);
    Route::get('/reviews', [AdminReviewController::class, 'index']);
    Route::post('/reviews', [AdminReviewController::class, 'store']);
    Route::put('/reviews/{id}', [AdminReviewController::class, 'update']);
    Route::post('/reviews/bulk-delete', [AdminReviewController::class, 'bulkDestroy']);
    Route::post('/reviews/bulk-approve', [AdminReviewController::class, 'bulkApprove']);
    Route::post('/reviews/bulk-reject', [AdminReviewController::class, 'bulkReject']);
    Route::patch('/reviews/{id}/approval', [AdminReviewController::class, 'toggleApproval']);
    Route::patch('/reviews/visibility', [AdminReviewController::class, 'toggleVisibility']);
    Route::delete('/reviews/{id}', [AdminReviewController::class, 'destroy']);

    // Banners & Promotional Campaigns
    Route::get('/banners', [AdminBannerController::class, 'index']);
    Route::get('/banners/{id}', [AdminBannerController::class, 'show']);
    Route::post('/banners', [AdminBannerController::class, 'store']);
    Route::post('/banners/upload-image', [AdminBannerController::class, 'uploadImage']);
    Route::post('/banners/reorder', [AdminBannerController::class, 'reorder']);
    Route::put('/banners/{id}', [AdminBannerController::class, 'update']);
    Route::patch('/banners/{id}/status', [AdminBannerController::class, 'toggleStatus']);
    Route::delete('/banners/{id}', [AdminBannerController::class, 'destroy']);

    // Staff & Access Control (Super Admin / Admin)
    Route::get('/staff', [AdminStaffController::class, 'index']);
    Route::post('/staff', [AdminStaffController::class, 'store']);
    Route::post('/staff/bulk-delete', [AdminStaffController::class, 'bulkDestroy']);
    Route::put('/staff/{id}', [AdminStaffController::class, 'update']);
    Route::delete('/staff/{id}', [AdminStaffController::class, 'destroy']);
    Route::post('/staff/{id}/suspend', [AdminStaffController::class, 'suspend']);
    Route::post('/staff/{id}/reactivate', [AdminStaffController::class, 'reactivate']);
    Route::post('/staff/{id}/promote', [AdminStaffController::class, 'promote'])->middleware('sliding-throttle:sensitive-admin-action');
    Route::post('/staff/{id}/demote', [AdminStaffController::class, 'demote'])->middleware('sliding-throttle:sensitive-admin-action');

    // Audit Trail & Logging
    Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);
    Route::post('/audit-logs/bulk-delete', [AdminAuditLogController::class, 'bulkDestroy'])->middleware('sliding-throttle:sensitive-admin-action');

    // System Settings & Defaults
    Route::get('/settings', [AdminSettingsController::class, 'index']);
    Route::put('/settings', [AdminSettingsController::class, 'update']);
    Route::get('/settings/extended', [AdminSettingsController::class, 'getExtendedSettings']);
    Route::put('/settings/group/{group}', [AdminSettingsController::class, 'updateGroup']);
    Route::post('/system/cache-clear', [AdminSettingsController::class, 'clearCache']);
    Route::post('/system/sitemap/generate', [AdminSettingsController::class, 'generateSitemap']);

    // Third-Party Integrations Hub
    Route::prefix('integrations')->group(function () {
        Route::get('/', [AdminIntegrationController::class, 'index']);
        Route::get('/{provider}', [AdminIntegrationController::class, 'show']);
        Route::put('/{provider}', [AdminIntegrationController::class, 'update']);
        Route::post('/{provider}/toggle', [AdminIntegrationController::class, 'toggle']);
        Route::post('/{provider}/test', [AdminIntegrationController::class, 'test']);
        Route::post('/{provider}/rotate-key', [AdminIntegrationController::class, 'rotateKey']);
    });

    // 14 Specialized Business Intelligence Reports
    Route::prefix('reports')->group(function () {
        Route::get('/{type}', [AdminReportController::class, 'show']);
        Route::get('/{type}/export', [AdminReportController::class, 'exportCsv']);
    });

    // Storefront Theme & UI Studio
    Route::get('/theme', [AdminThemeController::class, 'index']);
    Route::put('/theme', [AdminThemeController::class, 'update']);
    Route::post('/theme/reset', [AdminThemeController::class, 'resetDefaults']);

    // Extensible Brand Logos & Separated Favicon Management
    Route::prefix('branding')->group(function () {
        Route::get('/logos', [AdminBrandLogoController::class, 'index']);
        Route::post('/logos', [AdminBrandLogoController::class, 'store']);
        Route::put('/placements', [AdminBrandLogoController::class, 'batchUpdatePlacements']);
        Route::put('/logos/{id}', [AdminBrandLogoController::class, 'update']);
        Route::delete('/logos/{id}', [AdminBrandLogoController::class, 'destroy']);
        Route::post('/upload', [AdminBrandLogoController::class, 'upload']);
        Route::put('/favicon', [AdminBrandLogoController::class, 'updateFavicon']);
        Route::delete('/favicon', [AdminBrandLogoController::class, 'removeFavicon']);
    });

    // Dynamic Homepage Sections Builder
    Route::prefix('homepage/sections')->group(function () {
        Route::get('/', [AdminHomepageSectionController::class, 'index']);
        Route::post('/', [AdminHomepageSectionController::class, 'store']);
        Route::post('/reorder', [AdminHomepageSectionController::class, 'reorder']);
        Route::get('/{id}', [AdminHomepageSectionController::class, 'show']);
        Route::put('/{id}', [AdminHomepageSectionController::class, 'update']);
        Route::delete('/{id}', [AdminHomepageSectionController::class, 'destroy']);
        Route::patch('/{id}/toggle', [AdminHomepageSectionController::class, 'toggle']);
        Route::post('/{id}/duplicate', [AdminHomepageSectionController::class, 'duplicate']);
    });

    // Blog & Editorial Publishing
    Route::prefix('blog')->group(function () {
        Route::get('/summary', [AdminBlogController::class, 'summary']);
        Route::get('/posts', [AdminBlogController::class, 'posts']);
        Route::get('/posts/{id}', [AdminBlogController::class, 'showPost']);
        Route::post('/posts', [AdminBlogController::class, 'storePost']);
        Route::put('/posts/{id}', [AdminBlogController::class, 'updatePost']);
        Route::patch('/posts/{id}/status', [AdminBlogController::class, 'togglePostStatus']);
        Route::delete('/posts/{id}', [AdminBlogController::class, 'destroyPost']);

        Route::get('/categories', [AdminBlogController::class, 'categories']);
        Route::post('/categories', [AdminBlogController::class, 'storeCategory']);
        Route::put('/categories/{id}', [AdminBlogController::class, 'updateCategory']);
        Route::delete('/categories/{id}', [AdminBlogController::class, 'destroyCategory']);

        Route::get('/tags', [AdminBlogController::class, 'tags']);
        Route::post('/tags', [AdminBlogController::class, 'storeTag']);
        Route::put('/tags/{id}', [AdminBlogController::class, 'updateTag']);
        Route::delete('/tags/{id}', [AdminBlogController::class, 'destroyTag']);

        Route::get('/comments', [AdminBlogController::class, 'comments']);
        Route::patch('/comments/{id}/approval', [AdminBlogController::class, 'toggleCommentApproval']);
        Route::delete('/comments/{id}', [AdminBlogController::class, 'destroyComment']);
    });

    // Online Store CMS & Content Architecture
    Route::prefix('online-store')->group(function () {
        Route::get('/pages', [AdminOnlineStoreController::class, 'pages']);
        Route::get('/pages/{id}', [AdminOnlineStoreController::class, 'showPage']);
        Route::post('/pages', [AdminOnlineStoreController::class, 'storePage']);
        Route::put('/pages/{id}', [AdminOnlineStoreController::class, 'updatePage']);
        Route::patch('/pages/{id}/status', [AdminOnlineStoreController::class, 'togglePageStatus']);
        Route::delete('/pages/{id}', [AdminOnlineStoreController::class, 'destroyPage']);

        Route::get('/footer-columns', [AdminOnlineStoreController::class, 'footerColumns']);
        Route::post('/footer-columns', [AdminOnlineStoreController::class, 'storeFooterColumn']);
        Route::put('/footer-columns/{id}', [AdminOnlineStoreController::class, 'updateFooterColumn']);
        Route::delete('/footer-columns/{id}', [AdminOnlineStoreController::class, 'destroyFooterColumn']);
        Route::post('/footer-columns/reorder', [AdminOnlineStoreController::class, 'reorderFooterColumns']);

        Route::get('/footer-links', [AdminOnlineStoreController::class, 'footerLinks']);
        Route::post('/footer-links', [AdminOnlineStoreController::class, 'storeFooterLink']);
        Route::put('/footer-links/{id}', [AdminOnlineStoreController::class, 'updateFooterLink']);
        Route::delete('/footer-links/{id}', [AdminOnlineStoreController::class, 'destroyFooterLink']);
        Route::post('/footer-links/reorder', [AdminOnlineStoreController::class, 'reorderFooterLinks']);

        Route::get('/social-links', [AdminOnlineStoreController::class, 'socialLinks']);
        Route::post('/social-links', [AdminOnlineStoreController::class, 'storeSocialLink']);
        Route::put('/social-links/{id}', [AdminOnlineStoreController::class, 'updateSocialLink']);
        Route::delete('/social-links/{id}', [AdminOnlineStoreController::class, 'destroySocialLink']);

        // Navigation Menu Manager
        Route::prefix('navigation')->group(function () {
            Route::get('/', [AdminNavigationController::class, 'index']);
            Route::get('/tree', [AdminNavigationController::class, 'tree']);
            Route::get('/options', [AdminNavigationController::class, 'options']);
            Route::post('/', [AdminNavigationController::class, 'store']);
            Route::post('/reorder', [AdminNavigationController::class, 'reorder']);
            Route::get('/category-brands/{categoryId}', [AdminNavigationController::class, 'getCategoryBrands']);
            Route::match(['post', 'put'], '/category-brands/{categoryId}', [AdminNavigationController::class, 'updateCategoryBrands']);
            Route::get('/{id}', [AdminNavigationController::class, 'show']);
            Route::post('/{id}/duplicate', [AdminNavigationController::class, 'duplicate']);
            Route::put('/{id}', [AdminNavigationController::class, 'update']);
            Route::delete('/{id}', [AdminNavigationController::class, 'destroy']);
        });
    });

    // Dynamic Header Navigation System Alias
    Route::prefix('navigation')->group(function () {
        Route::get('/', [AdminNavigationController::class, 'index']);
        Route::get('/tree', [AdminNavigationController::class, 'tree']);
        Route::get('/options', [AdminNavigationController::class, 'options']);
        Route::post('/', [AdminNavigationController::class, 'store']);
        Route::post('/reorder', [AdminNavigationController::class, 'reorder']);
        Route::get('/category-brands/{categoryId}', [AdminNavigationController::class, 'getCategoryBrands']);
        Route::match(['post', 'put'], '/category-brands/{categoryId}', [AdminNavigationController::class, 'updateCategoryBrands']);
        Route::get('/{id}', [AdminNavigationController::class, 'show']);
        Route::post('/{id}/duplicate', [AdminNavigationController::class, 'duplicate']);
        Route::put('/{id}', [AdminNavigationController::class, 'update']);
        Route::delete('/{id}', [AdminNavigationController::class, 'destroy']);
    });
});

