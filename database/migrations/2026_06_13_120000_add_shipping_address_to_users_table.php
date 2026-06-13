<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The user's last shipping address, saved on opt-in during print
            // checkout so the next order can prefill it. Same JSON shape as the
            // per-order snapshot (firstName, lastName, street, …). Null until
            // the user chooses to save one.
            $table->json('shipping_address')->nullable()->after('feed_layout');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('shipping_address');
        });
    }
};
