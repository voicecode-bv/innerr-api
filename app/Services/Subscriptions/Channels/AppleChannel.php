<?php

namespace App\Services\Subscriptions\Channels;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Subscriptions\Apple\AppleJwsVerifier;
use App\Services\Subscriptions\Apple\AppStoreServerApi;
use App\Services\Subscriptions\Contracts\PaymentChannel;
use App\Services\Subscriptions\Dto\CheckoutResultDto;
use App\Services\Subscriptions\Dto\CreateCheckoutRequest;
use App\Services\Subscriptions\Dto\SubscriptionStatusDto;
use App\Services\Subscriptions\Dto\VerifyPurchaseRequest;
use App\Services\Subscriptions\Dto\WebhookOutcomeDto;
use App\Services\Subscriptions\Exceptions\NotImplementedException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class AppleChannel implements PaymentChannel
{
    public function __construct(
        private AppleJwsVerifier $verifier,
        private AppStoreServerApi $api,
        private string $environment = 'sandbox',
    ) {}

    public function identifier(): SubscriptionChannel
    {
        return SubscriptionChannel::Apple;
    }

    /**
     * Client (StoreKit 2) submits a signedTransaction JWS. We don't trust its
     * signature alone — we extract the originalTransactionId, then ask the
     * App Store Server API for the authoritative subscription state.
     */
    public function verifyClientPurchase(VerifyPurchaseRequest $dto): SubscriptionStatusDto
    {
        $payload = $this->verifier->decodeUnverified($dto->token);
        $originalTransactionId = (string) ($payload['originalTransactionId'] ?? '');

        if ($originalTransactionId === '') {
            throw new RuntimeException('Apple signedTransaction is missing originalTransactionId.');
        }

        $statuses = $this->api->getAllSubscriptionStatuses($originalTransactionId);

        return $this->dtoFromStatuses($statuses, $originalTransactionId);
    }

    public function createCheckout(CreateCheckoutRequest $dto): CheckoutResultDto
    {
        throw new NotImplementedException('Apple does not support server-side checkout; purchases happen via StoreKit.');
    }

    public function handleWebhook(Request $request): WebhookOutcomeDto
    {
        $signedPayload = (string) $request->input('signedPayload');

        if ($signedPayload === '') {
            throw new RuntimeException('Apple webhook is missing signedPayload.');
        }

        $outer = $this->verifier->verify($signedPayload);

        $signedTxJws = (string) ($outer['data']['signedTransactionInfo'] ?? '');
        $signedRenewalJws = (string) ($outer['data']['signedRenewalInfo'] ?? '');

        $transaction = $signedTxJws !== '' ? $this->verifier->verify($signedTxJws) : [];
        $renewal = $signedRenewalJws !== '' ? $this->verifier->verify($signedRenewalJws) : [];

        $notificationType = (string) ($outer['notificationType'] ?? '');
        $subtype = (string) ($outer['subtype'] ?? '');
        $eventType = $this->mapNotification($notificationType, $subtype);

        return new WebhookOutcomeDto(
            channel: SubscriptionChannel::Apple,
            type: $eventType,
            externalEventId: (string) ($outer['notificationUUID'] ?? ''),
            channelSubscriptionId: (string) ($transaction['originalTransactionId'] ?? ''),
            occurredAt: isset($outer['signedDate']) ? Carbon::createFromTimestampMs((int) $outer['signedDate']) : now(),
            payload: [
                'notificationType' => $notificationType,
                'subtype' => $subtype,
                'transaction' => $transaction,
                'renewal' => $renewal,
                'data_meta' => array_diff_key((array) ($outer['data'] ?? []), array_flip(['signedTransactionInfo', 'signedRenewalInfo'])),
            ],
        );
    }

    public function fetchAuthoritativeStatus(Subscription $subscription): SubscriptionStatusDto
    {
        $statuses = $this->api->getAllSubscriptionStatuses($subscription->channel_subscription_id);

        return $this->dtoFromStatuses($statuses, $subscription->channel_subscription_id);
    }

    public function cancel(Subscription $subscription): void
    {
        throw new NotImplementedException('Apple subscriptions can only be canceled by the user via iOS settings.');
    }

    public function refundGrant(Subscription $subscription, string $transactionId): void
    {
        throw new NotImplementedException('Apple refunds are observed via webhooks, not initiated server-side.');
    }

    /**
     * @param  array<string, mixed>  $statuses
     */
    private function dtoFromStatuses(array $statuses, string $originalTransactionId): SubscriptionStatusDto
    {
        $latest = $this->latestRenewalInfo($statuses);
        $statusCode = (int) ($latest['status'] ?? 0);
        $expiresMs = (int) ($latest['transaction']['expiresDate'] ?? 0);
        $autoRenew = (bool) ($latest['renewal']['autoRenewStatus'] ?? false);
        $productId = (string) ($latest['transaction']['productId'] ?? '');

        return new SubscriptionStatusDto(
            channel: SubscriptionChannel::Apple,
            channelSubscriptionId: $originalTransactionId,
            status: $this->mapStatusCode($statusCode),
            channelProductId: $productId !== '' ? $productId : null,
            currentPeriodStart: isset($latest['transaction']['purchaseDate'])
                ? Carbon::createFromTimestampMs((int) $latest['transaction']['purchaseDate'])
                : null,
            currentPeriodEnd: $expiresMs > 0 ? Carbon::createFromTimestampMs($expiresMs) : null,
            renewsAt: $autoRenew && $expiresMs > 0 ? Carbon::createFromTimestampMs($expiresMs) : null,
            autoRenew: $autoRenew,
            environment: (string) ($statuses['environment'] ?? $this->environment),
            metadata: [
                'inAppOwnershipType' => (string) ($latest['transaction']['inAppOwnershipType'] ?? ''),
                'productId' => $productId,
                'bundleId' => (string) ($statuses['bundleId'] ?? ''),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $statuses
     * @return array{status: int, transaction: array<string, mixed>, renewal: array<string, mixed>}
     */
    private function latestRenewalInfo(array $statuses): array
    {
        $first = ($statuses['data'][0] ?? null) ?: ($statuses['lastTransactions'][0] ?? null);

        if (! is_array($first)) {
            return ['status' => 0, 'transaction' => [], 'renewal' => []];
        }

        $signedTxJws = (string) ($first['signedTransactionInfo'] ?? '');
        $signedRenewalJws = (string) ($first['signedRenewalInfo'] ?? '');

        $transaction = $signedTxJws !== '' ? $this->verifier->decodeUnverified($signedTxJws) : [];
        $renewal = $signedRenewalJws !== '' ? $this->verifier->decodeUnverified($signedRenewalJws) : [];

        return [
            'status' => (int) ($first['status'] ?? 0),
            'transaction' => $transaction,
            'renewal' => $renewal,
        ];
    }

    private function mapStatusCode(int $code): SubscriptionStatus
    {
        return match ($code) {
            1 => SubscriptionStatus::Active,
            2 => SubscriptionStatus::Expired,
            3 => SubscriptionStatus::InGrace,
            4 => SubscriptionStatus::OnHold,
            5 => SubscriptionStatus::Canceled,
            default => SubscriptionStatus::Pending,
        };
    }

    private function mapNotification(string $type, string $subtype): SubscriptionEventType
    {
        return match ($type) {
            'SUBSCRIBED' => SubscriptionEventType::Started,
            'DID_RENEW' => SubscriptionEventType::Renewed,
            'DID_CHANGE_RENEWAL_STATUS' => $subtype === 'AUTO_RENEW_DISABLED' ? SubscriptionEventType::Canceled : SubscriptionEventType::PriceChange,
            'DID_FAIL_TO_RENEW' => $subtype === 'GRACE_PERIOD' ? SubscriptionEventType::EnteredGrace : SubscriptionEventType::EnteredGrace,
            'GRACE_PERIOD_EXPIRED' => SubscriptionEventType::Expired,
            'EXPIRED' => SubscriptionEventType::Expired,
            'REFUND', 'REVOKE' => SubscriptionEventType::Refunded,
            'PRICE_INCREASE', 'DID_CHANGE_RENEWAL_PREF' => SubscriptionEventType::PriceChange,
            'OFFER_REDEEMED' => SubscriptionEventType::Started,
            'TEST' => SubscriptionEventType::PriceChange,
            default => SubscriptionEventType::PriceChange,
        };
    }
}
