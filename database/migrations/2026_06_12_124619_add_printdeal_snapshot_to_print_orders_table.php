<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of the Printdeal product backing the order, taken at creation.
     * The admin can re-map or re-price products at any time; an order that is
     * already paid for must keep submitting with the configuration the user
     * bought.
     */
    public function up(): void
    {
        Schema::table('print_orders', function (Blueprint $table) {
            $table->string('printdeal_sku')->nullable()->after('shipping_address');
            $table->json('printdeal_attributes')->nullable()->after('printdeal_sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_orders', function (Blueprint $table) {
            $table->dropColumn(['printdeal_sku', 'printdeal_attributes']);
        });
    }
};
