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
        Schema::create('print_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            // Product key from config/print.php (calendar, album, mug, tshirt).
            $table->string('product');
            // Product-specific choices, e.g. {"size": "M"} for a t-shirt.
            $table->json('options')->nullable();
            // Snapshot of the selected photos: [{post_id, media_id, path}].
            $table->json('photos');
            $table->json('shipping_address');

            // Selling price charged to the user (fixed prices from config).
            $table->unsignedInteger('amount_minor');
            $table->char('currency', 3)->default('EUR');

            $table->string('status')->default('pending_payment')->index();
            $table->string('mollie_payment_id')->nullable()->index();

            $table->string('printdeal_order_id')->nullable()->index();
            $table->string('printdeal_order_number')->nullable();
            $table->string('printdeal_item_id')->nullable();
            // Raw orderline status as reported by Printdeal webhooks.
            $table->string('printdeal_status')->nullable();

            $table->string('pdf_path')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('print_orders');
    }
};
