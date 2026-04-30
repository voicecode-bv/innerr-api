<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->foreignId('price_id')->nullable()->constrained();
            $table->string('channel');
            $table->string('channel_subscription_id');
            $table->string('channel_customer_id')->nullable();
            $table->string('status');
            $table->string('environment')->default('production');
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->text('latest_receipt')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'channel_subscription_id']);
            $table->index(['user_id', 'status']);
            $table->index('current_period_end');
            $table->index(['channel', 'status']);
        });

        DB::statement("CREATE INDEX subscriptions_user_active ON subscriptions (user_id) WHERE status IN ('active','in_grace')");
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
