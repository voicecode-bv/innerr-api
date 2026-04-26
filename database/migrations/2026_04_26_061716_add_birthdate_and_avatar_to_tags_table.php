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
        Schema::table('tags', function (Blueprint $table) {
            $table->date('birthdate')->nullable()->after('name');
            $table->string('avatar')->nullable()->after('birthdate');
            $table->string('avatar_thumbnail')->nullable()->after('avatar');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn(['birthdate', 'avatar', 'avatar_thumbnail']);
        });
    }
};
