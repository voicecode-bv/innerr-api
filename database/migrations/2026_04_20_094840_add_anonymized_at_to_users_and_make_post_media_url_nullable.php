<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('email_verified_at');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->string('media_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->string('media_url')->nullable(false)->change();
        });
    }
};
