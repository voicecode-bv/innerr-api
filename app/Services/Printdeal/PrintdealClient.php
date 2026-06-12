<?php

namespace App\Services\Printdeal;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Printdeal API v3 (drukwerkdeal.nl).
 *
 * Authentication is JWT-based: POST /login with the api key + secret returns
 * a token that stays valid for 72 hours and cannot be refreshed. We cache it
 * for 70 hours and re-login once on a 401 in case it was revoked early.
 */
class PrintdealClient
{
    private const TOKEN_CACHE_KEY = 'printdeal:jwt';

    /**
     * @var array{base_url: string, api_key: ?string, secret: ?string, test_orders: bool, webhook_token: ?string}
     */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('services.printdeal');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function products(int $limit = 400, int $offset = 0): array
    {
        $response = $this->request()
            ->get('/products', ['limit' => $limit, 'offset' => $offset])
            ->throw();

        return $response->json('results', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function product(string $sku): array
    {
        return $this->request()->get("/products/{$sku}")->throw()->json();
    }

    /**
     * Validate a (partial) attribute selection. With an empty selection the
     * response's `remainingOptions` enumerates every attribute and its
     * allowed values, which makes this a fallback product-schema source when
     * the details endpoint has no data for a sku.
     *
     * @param  array<int, array{attribute: string, value: mixed}>  $attributes
     * @return array<string, mixed>
     */
    public function validateSelection(string $sku, array $attributes): array
    {
        return $this->request()
            ->post("/products/{$sku}/validate", $attributes)
            ->throw()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function prices(string $sku, array $payload): array
    {
        return $this->request()
            ->post("/products/{$sku}/prices", $payload)
            ->throw()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createOrder(array $payload): array
    {
        return $this->request()->post('/order', $payload)->throw()->json();
    }

    public function testOrdersEnabled(): bool
    {
        return (bool) ($this->config['test_orders'] ?? true);
    }

    /**
     * Subscribe a URL to Printdeal webhook events. Lives on a separate host
     * (webhook.api.printdeal.com) but shares the same JWT.
     *
     * @param  array<int, string>  $events
     * @return array<string, mixed>
     */
    public function createWebhookSubscription(string $url, array $events, string $description): array
    {
        return Http::baseUrl($this->config['webhook_base_url'])
            ->withToken($this->token())
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post('/webhooks', [
                'description' => $description,
                'url' => $url,
                'events' => $events,
            ])
            ->throw()
            ->json() ?? [];
    }

    /**
     * Authenticated request builder. On a 401 the cached token is dropped and
     * the request retried once with a fresh login, covering early revocation
     * within the 72-hour window.
     */
    private function request(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->withToken($this->token())
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->retry(2, 1000, function (\Exception $exception): bool {
                if (! $exception instanceof RequestException) {
                    return true;
                }

                if ($exception->response->status() === 401) {
                    Cache::forget(self::TOKEN_CACHE_KEY);

                    return true;
                }

                // Client errors (validation etc.) won't improve on retry.
                return $exception->response->serverError();
            }, throw: false);
    }

    private function token(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addHours(70), function (): string {
            $response = Http::baseUrl($this->config['base_url'])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post('/login', [
                    'apiKey' => $this->config['api_key'],
                    'secret' => $this->config['secret'],
                ])
                ->throw();

            return $response->json('token');
        });
    }
}
