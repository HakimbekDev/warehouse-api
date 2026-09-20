<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->decimal('purchase_price', 12, 2);
            $table->timestamps();

            $table->index('product_id');
            $table->unique(['batch_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_items');
    }
};
