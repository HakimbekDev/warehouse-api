<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Parent-child. NULL parent_id means this is a root category.
            $table->foreignId('parent_id')->nullable()
                ->constrained('categories')->cascadeOnDelete();

            // Only root categories carry a provider; children inherit it from the root.
            $table->foreignId('provider_id')->nullable()
                ->constrained('providers')->cascadeOnDelete();

            $table->timestamps();

            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
