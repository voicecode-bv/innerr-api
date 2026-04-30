<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('external_transaction_id');
            $table->string('kind');
            $table->integer('amount_minor');
            $table->char('currency', 3);
            $table->timestamp('occurred_at');
            $table->jsonb('payload')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_transaction_id']);
            $table->index(['user_id', 'occurred_at']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_transactions');
    }
};
