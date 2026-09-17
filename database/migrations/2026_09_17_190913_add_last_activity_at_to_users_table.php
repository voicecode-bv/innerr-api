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
        Schema::table('users', function (Blueprint $table) {
            // Last time the user made an authenticated request. Written at most
            // once per throttle window by the TrackLastActivity middleware, so
            // it is cheap enough to keep on the hot path for every request.
            $table->timestamp('last_activity_at')->nullable()->after('feature_tour_completed_at');
            $table->index('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_activity_at']);
            $table->dropColumn('last_activity_at');
        });
    }
};
