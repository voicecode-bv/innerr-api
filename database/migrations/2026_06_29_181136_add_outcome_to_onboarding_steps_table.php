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
        Schema::table('onboarding_steps', function (Blueprint $table) {
            // How the user left the step: 'reached' (opened but not advanced),
            // 'completed' (finished with the intended action) or 'skipped'
            // (advanced past without doing it). Existing rows pre-date this
            // distinction and only ever recorded an advance, so 'completed' is
            // the correct backfill default.
            $table->string('outcome')->default('completed')->after('step');

            // A 'reached' row has no terminal timestamp yet, so completed_at
            // must allow null. Existing rows keep their value.
            $table->timestamp('completed_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('onboarding_steps', function (Blueprint $table) {
            $table->dropColumn('outcome');
            $table->timestamp('completed_at')->nullable(false)->change();
        });
    }
};
