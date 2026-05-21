<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circle_user', function (Blueprint $table) {
            $table->string('role', 32)->default('member')->after('user_id');
            $table->index(['circle_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('circle_user', function (Blueprint $table) {
            $table->dropIndex(['circle_id', 'role']);
            $table->dropColumn('role');
        });
    }
};
