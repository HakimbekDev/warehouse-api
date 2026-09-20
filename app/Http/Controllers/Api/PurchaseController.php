<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RefundBatchRequest;
use App\Http\Requests\StorePurchaseRequest;
use App\Models\Batch;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;

class PurchaseController extends Controller
{
    public function __construct(private readonly PurchaseService $purchases)
    {
    }

    /** Buy products from a provider and add them to a storage. */
    public function store(StorePurchaseRequest $request): JsonResponse
    {
        $batch = $this->purchases->purchase($request->validated());

        return response()->json([
            'message' => 'Batch purchased and added to storage.',
            'data' => [
                'batch_id' => $batch->id,
                'provider' => $batch->provider->name,
                'storage' => $batch->storage->name,
                'purchased_at' => $batch->purchased_at->toDateString(),
                'items' => $batch->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name,
                    'qty' => $item->qty,
                    'purchase_price' => (float) $item->purchase_price,
                ]),
                'total_cost' => round($batch->items->sum(fn ($i) => $i->qty * (float) $i->purchase_price), 2),
            ],
        ], 201);
    }

    /** Return unsold goods from a batch to the provider, fully or partially. */
    public function refund(RefundBatchRequest $request, Batch $batch): JsonResponse
    {
        $refunds = $this->purchases->refund(
            $batch,
            $request->validated('items'),
            $request->validated('refunded_at') ?? now()->toDateString(),
        );

        return response()->json([
            'message' => 'Refund registered; the goods were deducted from storage.',
            'data' => [
                'batch_id' => $batch->id,
                'refunds' => array_map(fn ($refund) => [
                    'refund_id' => $refund->id,
                    'batch_item_id' => $refund->batch_item_id,
                    'qty' => $refund->qty,
                    'refunded_at' => $refund->refunded_at->toDateString(),
                ], $refunds),
            ],
        ], 201);
    }
}
