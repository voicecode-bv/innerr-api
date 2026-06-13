<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Human-friendly, sequential order number alongside the UUID primary key.
     * The UUID stays the technical identifier (routes, Mollie metadata,
     * webhook lookups); the number is what we show users and quote in support
     * and on the Mollie/Printdeal side. A Postgres sequence (starting at 1001
     * so the first orders don't read as #1) keeps assignment race-free.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS print_orders_number_seq START WITH 1001');

        Schema::table('print_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('number')->nullable()->after('id');
        });

        // Backfill existing orders in creation order so numbering reflects history.
        foreach (DB::table('print_orders')->orderBy('created_at')->pluck('id') as $id) {
            DB::table('print_orders')
                ->where('id', $id)
                ->update(['number' => DB::raw("nextval('print_orders_number_seq')")]);
        }

        Schema::table('print_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('number')->nullable(false)->change();
            $table->unique('number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('print_orders', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn('number');
        });

        DB::statement('DROP SEQUENCE IF EXISTS print_orders_number_seq');
    }
};
