<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('interval');
            $table->char('currency', 3);
            $table->unsignedInteger('amount_minor');
            $table->string('channel_product_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('external_metadata')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'channel_product_id']);
            $table->index(['plan_id', 'channel', 'interval', 'is_active']);
            $table->index(['channel', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
