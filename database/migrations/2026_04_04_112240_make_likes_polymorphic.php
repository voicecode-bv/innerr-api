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
        Schema::table('likes', function (Blueprint $table) {
            $table->dropForeign(['post_id']);
            $table->dropUnique(['user_id', 'post_id']);
        });

        Schema::table('likes', function (Blueprint $table) {
            $table->renameColumn('post_id', 'likeable_id');
        });

        Schema::table('likes', function (Blueprint $table) {
            $table->string('likeable_type')->nullable()->after('user_id');
        });

        DB::table('likes')->update(['likeable_type' => 'App\\Models\\Post']);

        Schema::table('likes', function (Blueprint $table) {
            $table->string('likeable_type')->nullable(false)->change();
        });

        Schema::table('likes', function (Blueprint $table) {
            $table->unique(['user_id', 'likeable_id', 'likeable_type']);
            $table->index(['likeable_id', 'likeable_type']);
        });
    }

    public function down(): void
    {
        Schema::table('likes', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'likeable_id', 'likeable_type']);
            $table->dropIndex(['likeable_id', 'likeable_type']);
        });

        Schema::table('likes', function (Blueprint $table) {
            $table->dropColumn('likeable_type');
        });

        Schema::table('likes', function (Blueprint $table) {
            $table->renameColumn('likeable_id', 'post_id');
        });

        Schema::table('likes', function (Blueprint $table) {
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->unique(['user_id', 'post_id']);
        });
    }
};
