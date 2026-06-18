<?php

namespace App\Services\Printdeal;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Printdeal API v2 (drukwerkdeal.nl).
 *
 * Authentication is header-based: every request carries the credentials as
 * `User-ID` and `API-Secret` headers, and the API version travels in the
 * `Accept` header. There is no login round-trip or token to cache.
 */
class PrintdealClient
{
    private const ACCEPT_HEADER = 'application/vnd.printdeal-api.v2';

    /**
     * @var array{base_url: string, webhook_base_url: string, api_key: ?string, secret: ?string, test_orders: bool, webhook_token: ?string}
     */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('services.printdeal');
    }

    /**
     * The orderable catalog. In v2 every "category" is the unit you order:
     * its sku is what /products/{sku}/attributes and order lines expect.
     *
     * @return array<int, array{name: string, sku: string, combinationsModifiedAt?: string}>
     */
    public function categories(): array
    {
        return $this->request()->get('/products/categories')->throw()->json() ?? [];
    }

    /**
     * Attribute schema for a product: an object keyed by attribute name whose
     * value is either a list of allowed values or a range object
     * ({minimum, maximum, increment, unitOfMeasure}). The `externals` key
     * holds free-input attributes with their validation rules.
     *
     * @return array<string, mixed>
     */
    public function attributes(string $sku): array
    {
        return $this->request()->get("/products/{$sku}/attributes")->throw()->json() ?? [];
    }

    /**
     * Validate a complete attribute selection (quantity included as the
     * `quantity` attribute) and retrieve its price. Invalid combinations
     * come back as a 400.
     *
     * @param  array<int, array{attribute: string, value: mixed}>  $attributes
     * @return array{price?: float, promisedArrivalDate?: ?string}
     */
    public function validateAndPrice(string $sku, array $attributes): array
    {
        return $this->request()
            ->post("/products/{$sku}", ['attributes' => $attributes])
            ->throw()
            ->json();
    }

    /**
     * Place an order. The response only carries the order's uuid; number,
     * status, and orderline ids come from a follow-up order() call.
     *
     * @param  array<string, mixed>  $payload
     * @return array{uuid?: string}
     */
    public function createOrder(array $payload): array
    {
        return $this->request()->post('/orders', $payload)->throw()->json();
    }

    /**
     * Order details (number, status, lines with their ids and statuses) by
     * numeric id or uuid.
     *
     * @return array<string, mixed>
     */
    public function order(string $idOrUuid): array
    {
        return $this->request()->get("/orders/{$idOrUuid}")->throw()->json() ?? [];
    }

    /**
     * List orders known to Printdeal, paged via limit/offset (the API caps the
     * limit at 50) and optionally filtered by Printdeal status (Open,
     * Confirmed, Complete, Cancelled, test). Returns the decoded response as-is
     * so callers can handle either a bare list or a wrapped envelope.
     *
     * @return array<string, mixed>|array<int, mixed>
     */
    public function orders(int $limit = 50, int $offset = 0, ?string $status = null): array
    {
        $query = [
            'limit' => max(1, min($limit, 50)),
            'offset' => max(0, $offset),
        ];

        if ($status !== null && $status !== '') {
            $query['status'] = $status;
        }

        return $this->request()->get('/orders', $query)->throw()->json() ?? [];
    }

    public function testOrdersEnabled(): bool
    {
        return (bool) ($this->config['test_orders'] ?? true);
    }

    /**
     * Subscribe a URL to Printdeal webhook events. Lives on a separate host
     * (webhook.api.printdeal.com) but uses the same credential headers.
     *
     * @param  array<int, string>  $events
     * @return array<string, mixed>
     */
    public function createWebhookSubscription(string $url, array $events, string $description): array
    {
        return $this->authenticated(Http::baseUrl($this->config['webhook_base_url']))
            ->timeout(15)
            ->post('/webhooks', [
                'description' => $description,
                'url' => $url,
                'events' => $events,
            ])
            ->throw()
            ->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return $this->authenticated(Http::baseUrl($this->config['base_url']))
            ->timeout(30)
            ->retry(2, 1000, function (\Exception $exception): bool {
                // Client errors (validation etc.) won't improve on retry.
                return ! $exception instanceof RequestException
                    || $exception->response->serverError();
            }, throw: false);
    }

    private function authenticated(PendingRequest $request): PendingRequest
    {
        return $request
            ->withHeaders([
                'User-ID' => (string) $this->config['api_key'],
                'API-Secret' => (string) $this->config['secret'],
                'Accept' => self::ACCEPT_HEADER,
            ])
            ->connectTimeout(5);
    }
}
