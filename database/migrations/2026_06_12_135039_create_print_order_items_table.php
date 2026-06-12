<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split print orders into order + line items so one order (and one Mollie
     * payment) can hold multiple products, each with its own photos, options,
     * artwork PDF, and Printdeal line status. The per-item columns move off
     * print_orders; no production orders exist yet, so nothing is ported.
     */
    public function up(): void
    {
        Schema::create('print_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('print_order_id')->constrained()->cascadeOnDelete();

            // App product key from config/print.php (calendar, album, ...).
            $table->string('app_product');
            // Snapshots from the offering at order time: display name,
            // Printdeal sku, and the fixed attribute set.
            $table->json('name')->nullable();
            $table->string('printdeal_sku');
            $table->json('printdeal_attributes');
            // User-chosen options, e.g. {"Size": "M"}.
            $table->json('options')->nullable();
            // [{post_id, media_id, path}]
            $table->json('photos');

            $table->unsignedInteger('amount_minor');
            $table->string('pdf_path')->nullable();

            $table->string('printdeal_item_id')->nullable()->index();
            $table->string('printdeal_status')->nullable();

            $table->timestamps();
        });

        Schema::table('print_orders', function (Blueprint $table) {
            $table->dropColumn([
                'product', 'options', 'photos',
                'printdeal_sku', 'printdeal_attributes',
                'printdeal_item_id', 'pdf_path',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_orders', function (Blueprint $table) {
            $table->string('product')->nullable();
            $table->json('options')->nullable();
            $table->json('photos')->nullable();
            $table->string('printdeal_sku')->nullable();
            $table->json('printdeal_attributes')->nullable();
            $table->string('printdeal_item_id')->nullable();
            $table->string('pdf_path')->nullable();
        });

        Schema::dropIfExists('print_order_items');
    }
};
