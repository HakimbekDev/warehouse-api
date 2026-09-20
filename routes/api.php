<?php

use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

// Buying in
Route::post('/purchases', [PurchaseController::class, 'store']);
Route::post('/batches/{batch}/refunds', [PurchaseController::class, 'refund']);

// Selling out
Route::get('/products/available', [ProductController::class, 'available']);
Route::post('/orders', [OrderController::class, 'store']);
Route::post('/orders/{order}/refunds', [OrderController::class, 'refund']);

// Reports
Route::get('/reports/storage-remaining', [ReportController::class, 'storageRemaining']);
Route::get('/reports/batch-profit', [ReportController::class, 'batchProfit']);
