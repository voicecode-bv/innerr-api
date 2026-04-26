<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->date('birthdate')->nullable();
            $table->string('avatar')->nullable();
            $table->string('avatar_thumbnail')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index('name');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
