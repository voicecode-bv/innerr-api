<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Preferred home feed layout: 'list' (classic) or 'masonry'.
            // Nullable means the user has not picked yet; the client then
            // shows a one-time chooser and defaults to masonry.
            $table->string('feed_layout', 10)->nullable()->after('searchable');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('feed_layout');
        });
    }
};
