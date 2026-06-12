<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generalize the t-shirt-specific `sizes` list into `user_options`:
     * the attributes a user picks in the app, each with its allowed values,
     * e.g. [{"attribute": "Size", "values": ["S", "M"]}]. Existing sizes
     * are carried over as a Size option.
     */
    public function up(): void
    {
        Schema::table('printdeal_products', function (Blueprint $table) {
            $table->json('user_options')->nullable()->after('order_attributes');
        });

        foreach (DB::table('printdeal_products')->whereNotNull('sizes')->get(['id', 'sizes']) as $row) {
            $sizes = json_decode($row->sizes, true);

            if (! is_array($sizes) || $sizes === []) {
                continue;
            }

            DB::table('printdeal_products')->where('id', $row->id)->update([
                'user_options' => json_encode([
                    ['attribute' => 'Size', 'values' => array_values($sizes)],
                ]),
            ]);
        }

        Schema::table('printdeal_products', function (Blueprint $table) {
            $table->dropColumn('sizes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('printdeal_products', function (Blueprint $table) {
            $table->json('sizes')->nullable()->after('order_attributes');
        });

        foreach (DB::table('printdeal_products')->whereNotNull('user_options')->get(['id', 'user_options']) as $row) {
            $options = json_decode($row->user_options, true) ?: [];
            $sizeOption = collect($options)->firstWhere('attribute', 'Size');

            if ($sizeOption !== null) {
                DB::table('printdeal_products')->where('id', $row->id)->update([
                    'sizes' => json_encode($sizeOption['values'] ?? []),
                ]);
            }
        }

        Schema::table('printdeal_products', function (Blueprint $table) {
            $table->dropColumn('user_options');
        });
    }
};
