<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The product's attribute schema as Printdeal reports it:
     * [{attribute, values: [...]}, ...]. Synced for configured products so
     * the admin form can suggest valid order attributes instead of requiring
     * a manual API lookup.
     */
    public function up(): void
    {
        Schema::table('printdeal_products', function (Blueprint $table) {
            $table->json('attribute_schema')->nullable()->after('sizes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('printdeal_products', function (Blueprint $table) {
            $table->dropColumn('attribute_schema');
        });
    }
};
