<?php

use Illuminate\Support\Facades\Route;

// API-only project; the endpoints live in routes/api.php under /api.
Route::get('/', fn () => response()->json([
    'name' => 'Warehouse / Trading API',
    'endpoints' => '/api — see README.md',
]));
