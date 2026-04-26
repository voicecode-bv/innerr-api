<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'type', 'name']);
            $table->unique(['user_id', 'name']);

            $table->dropColumn(['type', 'birthdate', 'avatar', 'avatar_thumbnail']);
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->string('type')->default('tag')->after('user_id');
            $table->date('birthdate')->nullable()->after('name');
            $table->string('avatar')->nullable()->after('birthdate');
            $table->string('avatar_thumbnail')->nullable()->after('avatar');

            $table->dropUnique(['user_id', 'name']);
            $table->unique(['user_id', 'type', 'name']);
        });
    }
};
