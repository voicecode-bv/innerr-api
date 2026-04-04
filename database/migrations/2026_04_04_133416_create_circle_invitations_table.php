<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circle_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['circle_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circle_invitations');
    }
};
