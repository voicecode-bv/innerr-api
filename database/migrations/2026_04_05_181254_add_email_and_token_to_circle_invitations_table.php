<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('circle_invitations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('email')->nullable()->after('user_id');
            $table->string('token')->nullable()->unique()->after('status');

            $table->unique(['circle_id', 'email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('circle_invitations', function (Blueprint $table) {
            $table->dropUnique(['circle_id', 'email', 'status']);
            $table->dropColumn(['email', 'token']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
