<?php

namespace App\Models;

use App\Enums\SubscriptionChannel;
use Database\Factories\SubscriptionTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'subscription_id', 'user_id', 'channel', 'external_transaction_id', 'kind',
    'amount_minor', 'currency', 'occurred_at', 'payload',
])]
class SubscriptionTransaction extends Model
{
    /** @use HasFactory<SubscriptionTransactionFactory> */
    use HasFactory;

    public const KIND_INITIAL = 'initial';

    public const KIND_RENEWAL = 'renewal';

    public const KIND_REFUND = 'refund';

    public const KIND_CHARGEBACK = 'chargeback';

    public const KIND_PRORATED_CREDIT = 'prorated_credit';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'channel' => SubscriptionChannel::class,
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
