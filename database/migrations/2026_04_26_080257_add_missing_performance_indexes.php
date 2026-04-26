<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('circle_user', function (Blueprint $table): void {
            $table->index(['user_id', 'circle_id']);
        });

        Schema::table('circle_post', function (Blueprint $table): void {
            $table->index('post_id');
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->index(['post_id', 'parent_comment_id']);
            $table->index('parent_comment_id');
        });

        Schema::table('circle_invitations', function (Blueprint $table): void {
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('circle_invitations', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'status']);
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropIndex(['parent_comment_id']);
            $table->dropIndex(['post_id', 'parent_comment_id']);
        });

        Schema::table('circle_post', function (Blueprint $table): void {
            $table->dropIndex(['post_id']);
        });

        Schema::table('circle_user', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'circle_id']);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'created_at']);
        });
    }
};
