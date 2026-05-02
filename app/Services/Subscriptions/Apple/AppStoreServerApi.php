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
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $base = $this->baseUrls[$this->environment] ?? null;

        if ($base === null) {
            throw new RuntimeException("Unknown Apple IAP environment [{$this->environment}].");
        }

        $response = $this->http->withToken($this->jwt->bearerToken())
            ->acceptJson()
            ->timeout(15)
            ->get($base.$path);

        $this->ensureOk($response, $path);

        return (array) $response->json();
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
