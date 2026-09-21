<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
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

        $purchasedAt = $data['purchased_at'] ?? now()->toDateString();

        return DB::transaction(function () use ($data, $purchasedAt) {
            $batch = Batch::create([
                'provider_id' => $data['provider_id'],
                'storage_id' => $data['storage_id'],
                'purchased_at' => $purchasedAt,
            ]);

            $now = now();

            // Written in bulk: one insert for the lines, one for their arrivals,
            // whatever the batch contains.
            BatchItem::insert(array_map(fn ($item) => [
                'batch_id' => $batch->id,
                'product_id' => $item['product_id'],
                'qty' => $item['qty'],
                'purchase_price' => $item['purchase_price'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $data['items']));

            StockMovement::insert($batch->items()->get()->map(fn (BatchItem $line) => [
                'batch_item_id' => $line->id,
                'type' => StockMovement::PURCHASE,
                'qty' => $line->qty,
                'moved_at' => $purchasedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

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
            $lines = $batch->items()->lockForUpdate()->get()->keyBy('product_id');

            // One query for the whole refund, not one per line.
            $available = $this->stock->availableQtyForBatchItems($lines->pluck('id')->all());

            $rows = [];
            $now = now();

            foreach ($items as $item) {
                $line = $lines->get((int) $item['product_id']);

                if (! $line) {
                    throw ValidationException::withMessages([
                        'items' => "Product {$item['product_id']} is not part of batch {$batch->id}.",
                    ]);
                }

                $left = $available[$line->id] ?? 0;

                if ($item['qty'] > $left) {
                    throw ValidationException::withMessages([
                        'items' => "Cannot refund {$item['qty']} of product {$item['product_id']}: "
                            . "only {$left} left unsold in storage.",
                    ]);
                }

                $rows[] = [
                    'batch_item_id' => $line->id,
                    'type' => StockMovement::PURCHASE_REFUND,
                    'qty' => -$item['qty'],
                    'moved_at' => $refundedAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            StockMovement::insert($rows);

            return $rows;
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
