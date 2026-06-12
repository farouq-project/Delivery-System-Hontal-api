<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DriverAppController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\RouteController;
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
    Route::middleware(['auth:sanctum', 'role:super_admin,merchant_owner,dispatcher'])->group(function () {

        // Geocoding utility
        Route::post('geocode/address', [OrderController::class, 'geocode']);

        // Customers
        Route::get('customers/search',    [CustomerController::class, 'search']);
        Route::apiResource('customers', CustomerController::class);

        // Drivers
        Route::get('drivers/live',                     [DriverController::class, 'live']);
        Route::get('drivers/{driver}/location-history',[DriverController::class, 'locationHistory']);
        Route::patch('drivers/{driver}/status',        [DriverController::class, 'updateStatus']);
        Route::apiResource('drivers', DriverController::class);

        // Orders
        Route::post('orders/{order}/assign',  [OrderController::class, 'assign']);
        Route::post('orders/{order}/status',  [OrderController::class, 'updateStatus']);
        Route::get('orders/{order}/history',  [OrderController::class, 'history']);
        Route::apiResource('orders', OrderController::class);

        // Routes
        Route::post('routes/generate',               [RouteController::class, 'generate']);
        Route::post('routes/{route}/lock',           [RouteController::class, 'lock']);
        Route::post('routes/{route}/unlock',         [RouteController::class, 'unlock']);
        Route::post('routes/{route}/reoptimize',     [RouteController::class, 'reoptimize']);
        Route::patch('routes/{route}/stops/{stop}',  [RouteController::class, 'updateStop']);
        Route::delete('routes/{route}/stops/{stop}', [RouteController::class, 'removeStop']);
        Route::apiResource('routes', RouteController::class)->except(['store']);
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
