<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('printdeal_products', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Synced mirror of the Printdeal catalog (printdeal:sync-products).
            $table->string('sku')->unique();
            $table->json('name');
            $table->timestamp('synced_at')->nullable();
            // Set when the sku disappears from the Printdeal product list.
            $table->timestamp('delisted_at')->nullable();

            // Shop configuration, managed in the Filament admin.
            $table->boolean('enabled')->default(false)->index();
            // Which app product this record backs (calendar/album/mug/tshirt).
            $table->string('app_product')->nullable()->index();
            // Attribute/value pairs submitted with Printdeal orders.
            $table->json('order_attributes')->nullable();
            // Variant sizes for grouped products (t-shirts).
            $table->json('sizes')->nullable();

            // Pricing: a fixed selling price wins; otherwise the synced
            // purchase price plus the margin percentage.
            $table->unsignedInteger('fixed_price_minor')->nullable();
            $table->decimal('margin_percent', 6, 2)->nullable();
            $table->unsignedInteger('purchase_price_minor')->nullable();
            $table->char('currency', 3)->default('EUR');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('printdeal_products');
    }
};
