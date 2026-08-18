<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BroadcastController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductIngredientController;
use App\Http\Controllers\Api\AdditionalGroupController;
use App\Http\Controllers\Api\IngredientController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\FinancialController;
use App\Http\Controllers\Api\FixedCostController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ZoneController;
use App\Http\Controllers\Api\WhatsappController;

// ── Rutas públicas ──────────────────────────────────────────────────────────

Route::post('/auth/login', [AuthController::class, 'login']);

// Broadcasting auth — requiere Sanctum pero va fuera del grupo para mayor compatibilidad
Route::post('/broadcasting/auth', [BroadcastController::class, 'auth'])
    ->middleware('auth:sanctum');

// Menú público — cliente escanea QR, no necesita token
Route::get('/menu/{restaurantSlug}', [MenuController::class, 'public']);
Route::post('/orders/qr', [OrderController::class, 'storeFromQr']);

// WhatsApp webhook — Meta verifica con GET, envía mensajes con POST
Route::get('/webhook/whatsapp',  [WhatsappController::class, 'verify']);
Route::post('/webhook/whatsapp', [WhatsappController::class, 'webhook']);

// ── Rutas protegidas ─────────────────────────────────────────────────────────

Route::middleware(['auth:sanctum', 'restaurant.scope'])->group(function () {

    // Auth
    Route::get('/auth/me',       [AuthController::class, 'me']);
    Route::post('/auth/logout',  [AuthController::class, 'logout']);

    // Pedidos
    Route::apiResource('orders', OrderController::class);
    Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);

    // Mesas
    Route::apiResource('tables', TableController::class);
    Route::get('/tables/{table}/orders', [TableController::class, 'activeOrders']);

    // Menú (gestión)
    Route::apiResource('categories',        CategoryController::class);
    Route::apiResource('products',          ProductController::class);
    Route::apiResource('additional-groups', AdditionalGroupController::class);

    // Inventario — módulo completo sujeto al plan
    Route::middleware('plan.feature:inventory')->group(function () {
        Route::apiResource('ingredients', IngredientController::class);
        Route::post('/ingredients/{ingredient}/movement', [InventoryController::class, 'addMovement']);
        Route::get('/inventory/alerts',                   [InventoryController::class, 'lowStockAlerts']);
        Route::get('/inventory/movements',                [InventoryController::class, 'movements']);

        // Receta del producto: sin ella no hay nada que descontar al cerrar.
        Route::get('/products/{product}/ingredients', [ProductIngredientController::class, 'index']);
        Route::put('/products/{product}/ingredients', [ProductIngredientController::class, 'sync']);
    });

    // Clientes
    Route::apiResource('customers', CustomerController::class);

    // Pagos
    Route::get('/payments',  [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store']);

    // Reportes
    Route::middleware('plan.feature:reports')->group(function () {
        Route::get('/reports/daily',    [ReportController::class, 'daily']);
        Route::get('/reports/products', [ReportController::class, 'topProducts']);
        Route::get('/reports/summary',  [ReportController::class, 'summary']);
    });

    // Módulo financiero — los costos fijos alimentan el punto de equilibrio,
    // así que van bajo la misma función de plan.
    Route::middleware('plan.feature:financials')->group(function () {
        Route::get('/financial/breakeven',  [FinancialController::class, 'breakeven']);
        Route::get('/financial/projection', [FinancialController::class, 'projection']);
        Route::get('/financial/dashboard',  [FinancialController::class, 'dashboard']);
        Route::put('/financial/goals',      [FinancialController::class, 'updateGoals']);
        Route::apiResource('fixed-costs', FixedCostController::class);
    });

    // Configuración del restaurante
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update']);

    // Solo admin
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::apiResource('zones', ZoneController::class);
    });
});
