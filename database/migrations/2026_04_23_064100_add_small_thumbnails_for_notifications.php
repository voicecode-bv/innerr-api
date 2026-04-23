<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_thumbnail')->nullable()->after('avatar');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->string('thumbnail_small_url')->nullable()->after('thumbnail_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_thumbnail');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('thumbnail_small_url');
        });
    }
};
