<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /** Products that currently have stock, for building a client order. */
    public function available(): JsonResponse
    {
        return response()->json([
            'data' => $this->stock->availableProducts(),
        ]);
    }
}
