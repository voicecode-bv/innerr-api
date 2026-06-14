<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-configured artwork dimensions for products whose PDF size depends
     * on the customer's choice (puzzle size, canvas size + frame). Shape:
     *   {
     *     "size_attribute": "Formaat",
     *     "sizes": [{"value": "90 x 60 cm", "width": 906, "height": 606}],
     *     "frame_attribute": "Frame",
     *     "frames": [{"value": "2 cm", "depth": 20}]
     *   }
     * Empty/null means the artwork generator falls back to config/print.php.
     */
    public function up(): void
    {
        Schema::table('printdeal_products', function (Blueprint $table): void {
            $table->json('artwork')->nullable()->after('user_options');
        });
    }

    public function down(): void
    {
        Schema::table('printdeal_products', function (Blueprint $table): void {
            $table->dropColumn('artwork');
        });
    }
};
