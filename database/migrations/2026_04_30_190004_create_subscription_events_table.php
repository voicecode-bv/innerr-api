<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel');
            $table->string('type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('external_event_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->jsonb('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'external_event_id']);
            $table->index(['subscription_id', 'occurred_at']);
            $table->index(['channel', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
