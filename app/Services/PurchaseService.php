<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /** Buy products from a provider and put them into one storage as a new batch. */
    public function purchase(array $data): Batch
    {
        $this->assertProductsBelongToProvider(
            array_column($data['items'], 'product_id'),
            (int) $data['provider_id'],
        );

        return DB::transaction(function () use ($data) {
            $batch = Batch::create([
                'provider_id' => $data['provider_id'],
                'storage_id' => $data['storage_id'],
                'purchased_at' => $data['purchased_at'] ?? now()->toDateString(),
            ]);

            foreach ($data['items'] as $item) {
                $batch->items()->create([
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'purchase_price' => $item['purchase_price'],
                ]);
            }

            return $batch->load('items.product', 'provider', 'storage');
        });
    }

    /**
     * Send unsold goods from a batch back to the provider, partially or in full.
     *
     * The cap is what is still in storage for that line, which already excludes
     * anything sold, so sold goods can never be refunded to the provider.
     */
    public function refund(Batch $batch, array $items, string $refundedAt): array
    {
        return DB::transaction(function () use ($batch, $items, $refundedAt) {
            $created = [];

            foreach ($items as $item) {
                $batchItem = $batch->items()
                    ->where('product_id', $item['product_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $batchItem) {
                    throw ValidationException::withMessages([
                        'items' => "Product {$item['product_id']} is not part of batch {$batch->id}.",
                    ]);
                }

                $available = $this->stock->availableQtyForBatchItem($batchItem->id);

                if ($item['qty'] > $available) {
                    throw ValidationException::withMessages([
                        'items' => "Cannot refund {$item['qty']} of product {$item['product_id']}: "
                            . "only {$available} left unsold in storage.",
                    ]);
                }

                $created[] = Refund::create([
                    'type' => Refund::TYPE_PURCHASE,
                    'batch_item_id' => $batchItem->id,
                    'qty' => $item['qty'],
                    'refunded_at' => $refundedAt,
                ]);
            }

            return $created;
        });
    }

    /**
     * A provider supplies only the categories under its own root category.
     *
     * The whole tree is loaded once and walked in memory rather than queried per
     * product; it is small, and this runs for every line of a purchase.
     */
    private function assertProductsBelongToProvider(array $productIds, int $providerId): void
    {
        $products = Product::findMany($productIds);
        $categories = Category::all()->keyBy('id');

        foreach ($products as $product) {
            $category = $categories[$product->category_id] ?? null;

            while ($category && $category->parent_id !== null) {
                $category = $categories[$category->parent_id] ?? null;
            }

            if (! $category || (int) $category->provider_id !== $providerId) {
                throw ValidationException::withMessages([
                    'items' => "Product \"{$product->name}\" is not supplied by the selected provider.",
                ]);
            }
        }
    }
}
