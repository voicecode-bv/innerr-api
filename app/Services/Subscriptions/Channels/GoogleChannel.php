<?php

namespace App\Services\Subscriptions\Channels;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Subscriptions\Contracts\PaymentChannel;
use App\Services\Subscriptions\Dto\CheckoutResultDto;
use App\Services\Subscriptions\Dto\CreateCheckoutRequest;
use App\Services\Subscriptions\Dto\SubscriptionStatusDto;
use App\Services\Subscriptions\Dto\VerifyPurchaseRequest;
use App\Services\Subscriptions\Dto\WebhookOutcomeDto;
use App\Services\Subscriptions\Exceptions\NotImplementedException;
use App\Services\Subscriptions\Google\PlayDeveloperApi;
use App\Services\Subscriptions\Google\PubSubOidcVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class GoogleChannel implements PaymentChannel
{
    public function __construct(
        private PlayDeveloperApi $api,
        private PubSubOidcVerifier $oidc,
    ) {}

    public function identifier(): SubscriptionChannel
    {
        return SubscriptionChannel::Google;
    }

    public function verifyClientPurchase(VerifyPurchaseRequest $dto): SubscriptionStatusDto
    {
        $purchaseToken = $dto->token;

        if ($purchaseToken === '') {
            throw new RuntimeException('Google verifyClientPurchase requires a purchase_token.');
        }

        $remote = $this->api->getSubscriptionV2($purchaseToken);

        return $this->dtoFromRemote($remote, $purchaseToken, $dto->productId);
    }

    public function createCheckout(CreateCheckoutRequest $dto): CheckoutResultDto
    {
        throw new NotImplementedException('Google Play purchases happen client-side via the Billing library.');
    }

    public function handleWebhook(Request $request): WebhookOutcomeDto
    {
        $bearer = (string) $request->bearerToken();

        if ($bearer !== '') {
            $this->oidc->verify($bearer);
        }

        $envelope = (array) $request->input('message', []);
        $messageId = (string) ($envelope['messageId'] ?? '');
        $rawData = (string) ($envelope['data'] ?? '');

        if ($messageId === '' || $rawData === '') {
            throw new RuntimeException('Google Pub/Sub envelope is missing message id or data.');
        }

        $payload = json_decode((string) base64_decode($rawData, true), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Google Pub/Sub data is not valid JSON.');
        }

        $notification = (array) ($payload['subscriptionNotification'] ?? []);
        $voided = (array) ($payload['voidedPurchaseNotification'] ?? []);
        $test = (array) ($payload['testNotification'] ?? []);

        if (! empty($voided)) {
            $purchaseToken = (string) ($voided['purchaseToken'] ?? '');
            $eventType = SubscriptionEventType::Refunded;
        } elseif (! empty($notification)) {
            $purchaseToken = (string) ($notification['purchaseToken'] ?? '');
            $eventType = $this->mapNotificationType((int) ($notification['notificationType'] ?? 0));
        } else {
            $purchaseToken = '';
            $eventType = SubscriptionEventType::PriceChange;
        }

        return new WebhookOutcomeDto(
            channel: SubscriptionChannel::Google,
            type: $eventType,
            externalEventId: $messageId,
            channelSubscriptionId: $purchaseToken,
            occurredAt: isset($payload['eventTimeMillis']) ? Carbon::createFromTimestampMs((int) $payload['eventTimeMillis']) : now(),
            payload: [
                'package_name' => $payload['packageName'] ?? null,
                'subscription_notification' => $notification,
                'voided_purchase_notification' => $voided,
                'test_notification' => $test,
            ],
        );
    }

    public function fetchAuthoritativeStatus(Subscription $subscription): SubscriptionStatusDto
    {
        $remote = $this->api->getSubscriptionV2($subscription->channel_subscription_id);

        return $this->dtoFromRemote($remote, $subscription->channel_subscription_id);
    }

    public function cancel(Subscription $subscription): void
    {
        throw new NotImplementedException('Google subscriptions can only be canceled by the user via Play Store.');
    }

    public function refundGrant(Subscription $subscription, string $transactionId): void
    {
        throw new NotImplementedException('Google refunds are observed via RTDN, not initiated server-side.');
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function dtoFromRemote(array $remote, string $purchaseToken, ?string $fallbackProductId = null): SubscriptionStatusDto
    {
        $state = (string) ($remote['subscriptionState'] ?? '');
        $lineItems = $remote['lineItems'] ?? [];
        $firstLine = is_array($lineItems) && ! empty($lineItems) ? (array) $lineItems[0] : [];
        $productId = (string) ($firstLine['productId'] ?? ($fallbackProductId ?? ''));
        $expiry = isset($firstLine['expiryTime']) ? Carbon::parse((string) $firstLine['expiryTime']) : null;
        $autoRenew = (bool) ($firstLine['autoRenewingPlan']['autoRenewEnabled'] ?? false);
        $startTime = isset($remote['startTime']) ? Carbon::parse((string) $remote['startTime']) : null;

        return new SubscriptionStatusDto(
            channel: SubscriptionChannel::Google,
            channelSubscriptionId: $purchaseToken,
            status: $this->mapState($state),
            channelProductId: $productId !== '' ? $productId : null,
            currentPeriodStart: $startTime,
            currentPeriodEnd: $expiry,
            renewsAt: $autoRenew ? $expiry : null,
            autoRenew: $autoRenew,
            environment: isset($remote['testPurchase']) ? 'sandbox' : 'production',
            metadata: [
                'linkedPurchaseToken' => $remote['linkedPurchaseToken'] ?? null,
                'regionCode' => $remote['regionCode'] ?? null,
                'acknowledgementState' => $remote['acknowledgementState'] ?? null,
            ],
        );
    }

    private function mapState(string $state): SubscriptionStatus
    {
        return match ($state) {
            'SUBSCRIPTION_STATE_ACTIVE' => SubscriptionStatus::Active,
            'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => SubscriptionStatus::InGrace,
            'SUBSCRIPTION_STATE_ON_HOLD' => SubscriptionStatus::OnHold,
            'SUBSCRIPTION_STATE_PAUSED' => SubscriptionStatus::Paused,
            'SUBSCRIPTION_STATE_CANCELED' => SubscriptionStatus::Canceled,
            'SUBSCRIPTION_STATE_EXPIRED' => SubscriptionStatus::Expired,
            'SUBSCRIPTION_STATE_PENDING' => SubscriptionStatus::Pending,
            default => SubscriptionStatus::Pending,
        };
    }

    /**
     * Map RTDN notificationType integer to SubscriptionEventType.
     * https://developer.android.com/google/play/billing/rtdn-reference
     */
    private function mapNotificationType(int $type): SubscriptionEventType
    {
        return match ($type) {
            1 => SubscriptionEventType::Recovered,
            2 => SubscriptionEventType::Renewed,
            3 => SubscriptionEventType::Canceled,
            4 => SubscriptionEventType::Started,
            5, 9 => SubscriptionEventType::PriceChange,
            6 => SubscriptionEventType::EnteredGrace,
            7 => SubscriptionEventType::Recovered,
            8 => SubscriptionEventType::PriceChange,
            10 => SubscriptionEventType::Paused,
            11 => SubscriptionEventType::Resumed,
            12 => SubscriptionEventType::Refunded,
            13 => SubscriptionEventType::Expired,
            default => SubscriptionEventType::PriceChange,
        };
    }
}
