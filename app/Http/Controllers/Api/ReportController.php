<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    /** Quantities left in storage as of the end of the given date. */
    public function storageRemaining(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'storage_id' => ['nullable', 'integer', 'exists:storages,id'],
        ]);

        $date = date('Y-m-d', strtotime($validated['date']));

        return response()->json([
            'date' => $date,
            'data' => $this->reports->remainingStock($date, $validated['storage_id'] ?? null),
        ]);
    }

    /** Profit per batch, with provider and client refunds taken out. */
    public function batchProfit(): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->batchProfit(),
        ]);
    }
}
