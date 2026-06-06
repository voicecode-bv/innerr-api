<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Denormalised counters of the user's own activity. Maintained by
            // the Like/Comment model events so the app can cheaply decide when
            // to prompt for an app review without aggregating on read.
            $table->unsignedInteger('likes_count')->default(0)->after('feed_layout');
            $table->unsignedInteger('comments_count')->default(0)->after('likes_count');
        });

        // Backfill existing rows from the source tables.
        DB::statement('UPDATE users SET likes_count = (SELECT COUNT(*) FROM likes WHERE likes.user_id = users.id)');
        DB::statement('UPDATE users SET comments_count = (SELECT COUNT(*) FROM comments WHERE comments.user_id = users.id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['likes_count', 'comments_count']);
        });
    }
};
