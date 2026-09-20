<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // The batch this line was picked from (FIFO, assigned by the backend).
            // The product is reached through batch_items.
            $table->foreignId('batch_item_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('qty');

            // Price at the moment of sale, so later product price edits do not rewrite history.
            $table->decimal('sale_price', 12, 2);

            $table->timestamps();

            $table->index('batch_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
