<?php

namespace App\Services\Subscriptions\Apple;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use RuntimeException;

class AppStoreServerApi
{
    public function __construct(
        private HttpFactory $http,
        private AppStoreServerJwt $jwt,
        private string $environment,
        /** @var array<string, string> */
        private array $baseUrls,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getAllSubscriptionStatuses(string $originalTransactionId): array
    {
        return $this->get("/inApps/v1/subscriptions/{$originalTransactionId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransactionInfo(string $transactionId): array
    {
        return $this->get("/inApps/v1/transactions/{$transactionId}");
    }

    /**
     * Asks Apple to send a TEST notification to the configured server URL.
     *
     * @return array<string, mixed>
     */
    public function requestTestNotification(): array
    {
        return $this->post('/inApps/v1/notifications/test');
    }

    /**
     * Looks up the delivery status of a previously requested test notification.
     *
     * @return array<string, mixed>
     */
    public function getTestNotificationStatus(string $testNotificationToken): array
    {
        return $this->get("/inApps/v1/notifications/test/{$testNotificationToken}");
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $response = $this->http->withToken($this->jwt->bearerToken())
            ->acceptJson()
            ->timeout(15)
            ->get($this->base().$path);

        $this->ensureOk($response, $path);

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body = []): array
    {
        $response = $this->http->withToken($this->jwt->bearerToken())
            ->acceptJson()
            ->timeout(15)
            ->post($this->base().$path, $body);

        $this->ensureOk($response, $path);

        return (array) $response->json();
    }

    private function base(): string
    {
        $base = $this->baseUrls[$this->environment] ?? null;

        if ($base === null) {
            throw new RuntimeException("Unknown Apple IAP environment [{$this->environment}].");
        }

        return $base;
    }

    private function ensureOk(Response $response, string $path): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Apple App Store Server API request to %s failed with status %d: %s',
            $path,
            $response->status(),
            (string) $response->body(),
        ));
    }
}
