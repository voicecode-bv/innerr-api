<?php

namespace App\Services\Subscriptions\Google;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use RuntimeException;

class PlayDeveloperApi
{
    public function __construct(
        private HttpFactory $http,
        private GoogleAccessTokenClient $tokens,
        private string $packageName,
        private string $baseUrl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getSubscriptionV2(string $purchaseToken): array
    {
        return $this->get(sprintf(
            '/androidpublisher/v3/applications/%s/purchases/subscriptionsv2/tokens/%s',
            rawurlencode($this->packageName),
            rawurlencode($purchaseToken),
        ));
    }

    public function acknowledge(string $productId, string $purchaseToken): void
    {
        $response = $this->http->withToken($this->tokens->bearer())
            ->acceptJson()
            ->timeout(15)
            ->post($this->baseUrl.sprintf(
                '/androidpublisher/v3/applications/%s/purchases/subscriptions/%s/tokens/%s:acknowledge',
                rawurlencode($this->packageName),
                rawurlencode($productId),
                rawurlencode($purchaseToken),
            ));

        $this->ensureOk($response, 'acknowledge');
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $response = $this->http->withToken($this->tokens->bearer())
            ->acceptJson()
            ->timeout(15)
            ->get($this->baseUrl.$path);

        $this->ensureOk($response, $path);

        return (array) $response->json();
    }

    private function ensureOk(Response $response, string $path): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Google Play Developer API call to %s failed with status %d: %s',
            $path,
            $response->status(),
            (string) $response->body(),
        ));
    }
}
