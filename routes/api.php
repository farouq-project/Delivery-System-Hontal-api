<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerDomainController;
use App\Http\Controllers\Api\V1\ExecutiveDashboardController;
use App\Http\Controllers\Api\V1\FeaturesController;
use App\Http\Controllers\Api\V1\MerchantPlatformController;
use App\Http\Controllers\Api\V1\DriverAppController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RouteController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ─── AUTH ─────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout',          [AuthController::class, 'logout']);
            Route::get('me',               [AuthController::class, 'me']);
            Route::patch('me',             [AuthController::class, 'update']);
            Route::post('change-password', [AuthController::class, 'changePassword']);
        });
    });

    // ─── DISPATCHER / OWNER ROUTES ────────────────────────────────────
    // kasir and developer are included so they can access operational endpoints.
    // Fine-grained role checks (owner-only actions) are enforced inside each controller.
    Route::middleware(['auth:sanctum', 'role:super_admin,merchant_owner,dispatcher,kasir,developer'])->group(function () {

        // Feature flags for current merchant
        Route::get('features', [FeaturesController::class, 'index']);

        // Executive Dashboard (Phase 2B — role-gated inside controller)
        Route::get('dashboard/executive', [ExecutiveDashboardController::class, 'index']);

        // Geocoding utility
        Route::post('geocode/address', [OrderController::class, 'geocode']);

        // Customers
        Route::get('customers/search',           [CustomerController::class, 'search']);
        Route::get('customers/template',         [CustomerController::class, 'downloadTemplate']);
        Route::post('customers/bulk-delete',          [CustomerController::class, 'bulkDelete']);
        Route::post('customers/bulk-update-cluster',  [CustomerController::class, 'bulkUpdateCluster']);
        Route::post('customers/deduplicate',          [CustomerController::class, 'deduplicate']);
        Route::post('customers/import',          [CustomerController::class, 'import']);
        Route::apiResource('customers', CustomerController::class);

        // Drivers
        Route::get('drivers/live',                     [DriverController::class, 'live']);
        Route::get('drivers/{driver}/location-history',[DriverController::class, 'locationHistory']);
        Route::patch('drivers/{driver}/status',        [DriverController::class, 'updateStatus']);
        Route::apiResource('drivers', DriverController::class);

        // Orders
        Route::get('orders/product-suggestions', [OrderController::class, 'productSuggestions']);
        Route::get('orders/klotters',            [OrderController::class, 'klotters']);
        Route::post('orders/bulk-assign',          [OrderController::class, 'bulkAssign']);
        Route::post('orders/bulk-delete',          [OrderController::class, 'bulkDelete']);
        Route::post('orders/bulk-unassign',        [OrderController::class, 'bulkUnassign']);
        Route::post('orders/bulk-update-cashier',  [OrderController::class, 'bulkUpdateCashier']);
        Route::post('orders/{order}/assign',  [OrderController::class, 'assign']);
        Route::post('orders/{order}/unassign', [OrderController::class, 'unassign']);
        Route::post('orders/{order}/status',  [OrderController::class, 'updateStatus']);
        Route::get('orders/{order}/history',  [OrderController::class, 'history']);
        Route::apiResource('orders', OrderController::class);

        // Reports
        Route::get('reports/cashier-summary', [ReportController::class, 'cashierSummary']);

        // Routes
        Route::post('routes/generate',               [RouteController::class, 'generate']);
        Route::post('routes/assign-order',           [RouteController::class, 'assignOrder']);
        Route::post('routes/assign-orders',          [RouteController::class, 'assignOrders']);
        Route::post('routes/{route}/lock',           [RouteController::class, 'lock']);
        Route::post('routes/{route}/unlock',         [RouteController::class, 'unlock']);
        Route::post('routes/{route}/reset',            [RouteController::class, 'reset']);
        Route::post('routes/{route}/reset-unassigned', [RouteController::class, 'resetUnassigned']);
        Route::post('routes/{route}/reoptimize',     [RouteController::class, 'reoptimize']);
        Route::patch('routes/{route}/stops/{stop}',  [RouteController::class, 'updateStop']);
        Route::delete('routes/{route}/stops/{stop}', [RouteController::class, 'removeStop']);
        Route::apiResource('routes', RouteController::class)->except(['store']);

        // Settings
        Route::get('settings',   [SettingsController::class, 'show']);
        Route::patch('settings', [SettingsController::class, 'update']);

        // Cashier names (per-merchant, owner-only write)
        Route::get('settings/cashiers',         [SettingsController::class, 'indexCashiers']);
        Route::post('settings/cashiers',        [SettingsController::class, 'storeCashier']);
        Route::delete('settings/cashiers/{id}', [SettingsController::class, 'destroyCashier']);

        // Cluster names (per-merchant, owner-only write)
        Route::get('settings/clusters',         [SettingsController::class, 'indexClusters']);
        Route::post('settings/clusters',        [SettingsController::class, 'storeCluster']);
        Route::delete('settings/clusters/{id}', [SettingsController::class, 'destroyCluster']);

        // Payment methods (read-only for all authenticated roles — used in order creation)
        Route::get('settings/payment-methods', [SettingsController::class, 'indexPaymentMethods']);

        // ─── Merchant Platform (Phase 3 — role-gated inside controller) ─
        Route::prefix('settings/platform')->group(function () {
            // Business Profile
            Route::get('profile',  [MerchantPlatformController::class, 'getProfile']);
            Route::patch('profile', [MerchantPlatformController::class, 'updateProfile']);

            // Operational
            Route::get('operational',  [MerchantPlatformController::class, 'getOperational']);
            Route::patch('operational', [MerchantPlatformController::class, 'updateOperational']);

            // Business Hours
            Route::get('hours',  [MerchantPlatformController::class, 'getHours']);
            Route::patch('hours', [MerchantPlatformController::class, 'updateHours']);

            // Invoice
            Route::get('invoice',  [MerchantPlatformController::class, 'getInvoice']);
            Route::patch('invoice', [MerchantPlatformController::class, 'updateInvoice']);

            // Tracking
            Route::get('tracking',  [MerchantPlatformController::class, 'getTracking']);
            Route::patch('tracking', [MerchantPlatformController::class, 'updateTracking']);

            // Notifications
            Route::get('notifications',  [MerchantPlatformController::class, 'getNotifications']);
            Route::patch('notifications', [MerchantPlatformController::class, 'updateNotifications']);

            // Payment Methods
            Route::get('payment-methods',            [MerchantPlatformController::class, 'indexPaymentMethods']);
            Route::post('payment-methods',           [MerchantPlatformController::class, 'storePaymentMethod']);
            Route::patch('payment-methods/reorder',  [MerchantPlatformController::class, 'reorderPaymentMethods']);
            Route::patch('payment-methods/{id}',     [MerchantPlatformController::class, 'updatePaymentMethod']);

            // Branches
            Route::get('branches',         [MerchantPlatformController::class, 'indexBranches']);
            Route::post('branches',        [MerchantPlatformController::class, 'storeBranch']);
            Route::patch('branches/{id}',  [MerchantPlatformController::class, 'updateBranch']);
            Route::delete('branches/{id}', [MerchantPlatformController::class, 'destroyBranch']);
        });
    });

    // ─── CUSTOMER DOMAIN (Phase 2A — feature-gated per merchant) ────
    Route::middleware(['auth:sanctum', 'role:super_admin,merchant_owner,dispatcher,kasir,developer'])
        ->prefix('customer-domain')
        ->group(function () {
            // Per-customer: profile, timeline, metrics
            Route::get('customers/{customerId}/profile',         [CustomerDomainController::class, 'profile']);
            Route::get('customers/{customerId}/timeline',        [CustomerDomainController::class, 'timeline']);
            Route::get('customers/{customerId}/metrics',         [CustomerDomainController::class, 'metrics']);
            Route::get('customers/{customerId}/tags',            [CustomerDomainController::class, 'customerTags']);
            Route::post('customers/{customerId}/tags/{tagId}',   [CustomerDomainController::class, 'assignTag']);
            Route::delete('customers/{customerId}/tags/{tagId}', [CustomerDomainController::class, 'removeTag']);

            // Tag management (write restricted to owner in controller)
            Route::get('tags',             [CustomerDomainController::class, 'indexTags']);
            Route::post('tags',            [CustomerDomainController::class, 'storeTag']);
            Route::put('tags/{tagId}',     [CustomerDomainController::class, 'updateTag']);
            Route::delete('tags/{tagId}',  [CustomerDomainController::class, 'destroyTag']);
        });

    // ─── USER MANAGEMENT (developer / super_admin / merchant_owner) ───
    Route::middleware(['auth:sanctum', 'role:super_admin,developer,merchant_owner'])->group(function () {
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::apiResource('users', UserController::class);
    });

    // ─── DRIVER APP ROUTES ────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'role:driver'])->prefix('driver')->group(function () {
        Route::get('me',                        [DriverAppController::class, 'me']);
        Route::get('today',                     [DriverAppController::class, 'today']);
        Route::patch('location',                [DriverAppController::class, 'updateLocation']);
        Route::patch('status',                  [DriverAppController::class, 'updateStatus']);
        Route::post('stops/{stopId}/deliver',   [DriverAppController::class, 'deliver']);
        Route::post('stops/{stopId}/fail',      [DriverAppController::class, 'fail']);
        Route::get('history',                   [DriverAppController::class, 'history']);
    });
});
