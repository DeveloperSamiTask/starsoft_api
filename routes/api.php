<?php

use App\Http\Controllers\Api\AreaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\OCApi;
use App\Http\Controllers\Api\OrdersApi;
use App\Http\Controllers\Api\Product\FamilyController;
use App\Http\Controllers\Api\Product\TypeController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;


Route::group(['middleware' => ['auth:sanctum']], function () {
    Route::get('/purchase-orders', [OrdersApi::class, 'getOrders']);
    Route::get('/purchase-orders/export', [OrdersApi::class, 'export']);
    Route::get('/purchase-orders/export-with-details', [OrdersApi::class, 'exportWithDetails']);
    Route::post('/details-order', [OrdersApi::class, 'getOrder']);
    Route::post('/mark-as-read', [OrdersApi::class, 'markAsRead']);
    Route::post('/handle-approval', [OrdersApi::class, 'handleApproval']);
    Route::get('/ocs', [OCApi::class, 'showOrder']);
    Route::get('/companies', [CompanyController::class, 'show']);
    Route::get('/families', [FamilyController::class, 'show']);
    Route::get('/types', [TypeController::class, 'show']);
    Route::get('/areas', [AreaController::class, 'filterCompany']);
    Route::get('/staff', [StaffController::class, 'filterStaff']);
    
    Route::controller(SupplierController::class)
        ->prefix('suppliers')
        ->group(function () {
            Route::get('/', 'getSuppliers');
            Route::get('/view/{id}', 'getSupplier');
            Route::get('/ocs', 'getSupplierOrders');
        });

    Route::controller(ProductController::class)
        ->prefix('products')
        ->group(function () {
            Route::get('/', 'getProducts');
            Route::get('/view/{id}', 'getProduct');
            Route::get('/ocs', 'getProductOrders');
        });
    Route::controller(UserController::class)
        ->prefix('users')
        ->group(function () {
            Route::get('/list', 'getUsers');
            Route::post('/add', 'store');
            Route::put('/update/{id}', 'update');
            Route::put('/update-profile/{id}', 'updateProfile');
            Route::get('/view/{id}', 'getUser');
            Route::get('/companies', 'userCompanies');
            Route::get('companyUser/{userId}/{companyId}', 'getIdCompanyUser');
            Route::post('addCompanyUser','addCompanyUser');
        });
    Route::controller(ReportController::class)
        ->prefix('reports')
        ->group(function () {
            Route::get('/suppliers', 'reportSuppliers');
            Route::get('/areas', 'reportAreas');
            Route::get('/products', 'reportSupplierProductsAreas');
            Route::get('/products/export', 'exportAreaOrders');
            Route::get('/areas-by-orders', 'reportAreasByOrders');
            Route::get('/monthly', 'reportMonthlyExpenses');
        });
});

Route::middleware(['auth:sanctum'])->post('/keep-alive', [AuthController::class, 'keepAlive']);



Route::group(['prefix' => 'auth'], function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('register', [AuthController::class, 'register']);

    Route::group(['middleware' => 'auth:sanctum'], function () {
        Route::get('user', [AuthController::class, 'user']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});




