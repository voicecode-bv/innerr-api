<?php

namespace App\Http\Controllers\Api\Print;

use App\Enums\MediaStatus;
use App\Enums\PrintOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePrintOrderRequest;
use App\Models\PostMedia;
use App\Models\PrintdealProduct;
use App\Models\PrintOrder;
use App\Services\Printdeal\PrintArtworkGenerator;
use App\Services\Printdeal\PrintOfferingPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;

class PrintOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = PrintOrder::query()
            ->with('items')
            ->whereBelongsTo($request->user())
            ->latest()
            ->limit(50)
            ->get();

        return new JsonResponse(['data' => $orders->map($this->toPayload(...))]);
    }

    public function show(Request $request, PrintOrder $printOrder): JsonResponse
    {
        abort_unless($printOrder->user_id === $request->user()->id, 404);

        return new JsonResponse(['data' => $this->toPayload($printOrder)]);
    }

    /**
     * Create a multi-item print order and its Mollie payment. The order
     * stays `pending_payment` until the Mollie webhook confirms; only then
     * does the SubmitPrintOrder job place it at Printdeal.
     */
    public function store(
        StorePrintOrderRequest $request,
        MollieApiClient $mollie,
        PrintOfferingPricing $pricing,
    ): JsonResponse {
        $user = $request->user();
        $offerings = $request->offerings();
        $requestedItems = $request->validated('items');

        // Price every line for its exact option combination (a 1000-piece
        // puzzle costs more than a 96-piece one); refusing beats charging a
        // wrong amount when no price can be determined. The artwork size is
        // resolved here too: a size-configured product whose chosen options
        // don't map to a size would otherwise be printed at the wrong fallback
        // box, so refusing beats shipping a wrong-sized, paid product.
        $itemAmounts = [];
        $itemDimensions = [];

        foreach ($requestedItems as $index => $item) {
            $offering = $offerings->get($item['offering_id']);
            $amount = $offering !== null && $offering->isOrderable()
                ? $pricing->sellingPriceMinor($offering, $item['options'] ?? [])
                : null;

            if ($amount === null) {
                Log::channel('print')->warning('Print order rejected: product unavailable or unpriceable.', [
                    'user_id' => $user->id,
                    'offering_id' => $item['offering_id'] ?? null,
                    'app_product' => $offering?->app_product,
                    'options' => $item['options'] ?? [],
                ]);

                return new JsonResponse([
                    'message' => 'One of the products is not available anymore.',
                    'error_code' => 'product_unavailable',
                ], 422);
            }

            $dimensions = $offering->artworkDimensions($item['options'] ?? []);

            if ($offering->artworkSizingConfigured() && $dimensions === null) {
                Log::channel('print')->error('Print order rejected: artwork size could not be resolved.', [
                    'user_id' => $user->id,
                    'offering_id' => $item['offering_id'] ?? null,
                    'app_product' => $offering->app_product,
                    'options' => $item['options'] ?? [],
                ]);

                return new JsonResponse([
                    'message' => 'One of the products is not available anymore.',
                    'error_code' => 'product_unavailable',
                ], 422);
            }

            $itemAmounts[$index] = $amount;
            $itemDimensions[$index] = $dimensions;
        }

        $totalMinor = array_sum($itemAmounts);

        $order = DB::transaction(function () use ($request, $user, $offerings, $requestedItems, $itemAmounts, $itemDimensions, $totalMinor): PrintOrder {
            $order = PrintOrder::query()->create([
                'user_id' => $user->id,
                'shipping_address' => $request->shippingAddress(),
                'amount_minor' => $totalMinor,
                'currency' => 'EUR',
                'status' => PrintOrderStatus::PendingPayment,
            ]);

            foreach ($requestedItems as $index => $item) {
                /** @var PrintdealProduct $offering */
                $offering = $offerings->get($item['offering_id']);

                // Sku, attributes, name, price, and artwork size are
                // snapshotted: the admin can re-map, re-price, or re-dimension
                // offerings later without affecting orders already placed.
                $artworkDimensions = $itemDimensions[$index];

                $order->items()->create([
                    'app_product' => $offering->app_product,
                    'name' => $offering->name,
                    'printdeal_sku' => $offering->sku,
                    'printdeal_attributes' => $offering->order_attributes ?? [],
                    'options' => ($item['options'] ?? []) !== [] ? $item['options'] : null,
                    'artwork_width_mm' => $artworkDimensions['width'] ?? null,
                    'artwork_height_mm' => $artworkDimensions['height'] ?? null,
                    'photos' => $this->resolvePhotos($request, $item['photos'], $artworkDimensions, $offering->app_product),
                    'amount_minor' => $itemAmounts[$index],
                ]);
            }

            return $order;
        });

        Log::channel('print')->info('Print order created.', [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'user_id' => $user->id,
            'item_count' => count($requestedItems),
            'amount_minor' => $totalMinor,
            'currency' => $order->currency,
            // Per-item config so two otherwise-identical products (same canvas,
            // different frame/size) are distinguishable in the log.
            'items' => collect($requestedItems)->map(function (array $item, int $index) use ($offerings, $itemAmounts, $itemDimensions): array {
                $offering = $offerings->get($item['offering_id']);
                $options = $item['options'] ?? [];

                return [
                    'app_product' => $offering?->app_product,
                    'options' => $options !== [] ? $options : null,
                    'artwork_width_mm' => $itemDimensions[$index]['width'] ?? null,
                    'artwork_height_mm' => $itemDimensions[$index]['height'] ?? null,
                    'amount_minor' => $itemAmounts[$index] ?? null,
                ];
            })->all(),
        ]);

        // Remember the address for next time, but only on opt-in. Stored as the
        // same snapshot shape the order keeps, so checkout can prefill it.
        if ($request->boolean('save_address')) {
            $user->update(['shipping_address' => $request->shippingAddress()]);
        }

        $payload = [
            'amount' => [
                'currency' => $order->currency,
                'value' => number_format($totalMinor / 100, 2, '.', ''),
            ],
            'description' => "innerr print order #{$order->number}",
            'redirectUrl' => $request->validated('redirect_url'),
            'metadata' => [
                'kind' => 'print_order',
                'print_order_id' => $order->id,
                'user_id' => $user->id,
            ],
        ];

        // Mollie rejects the whole payment when the webhook URL is not publicly
        // reachable (a local/dev host like *.test or localhost), so omit it
        // there. Production keeps it; local checkout then works without the
        // status webhook, polled instead.
        $webhookUrl = $this->reachableWebhookUrl();

        if ($webhookUrl !== null) {
            $payload['webhookUrl'] = $webhookUrl;
        } else {
            Log::channel('print')->warning('Print order payment created without a webhook URL; host is not publicly reachable.', [
                'order_id' => $order->id,
                'webhook_url' => URL::route('api.webhooks.print.mollie'),
            ]);
        }

        try {
            $payment = $mollie->payments->create($payload);
        } catch (ApiException $e) {
            $order->update(['status' => PrintOrderStatus::Canceled]);

            Log::channel('print')->error('Print order payment could not be started; order canceled.', [
                'order_id' => $order->id,
                'order_number' => $order->number,
                'amount_minor' => $totalMinor,
                'message' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'message' => 'Could not start the payment.',
                'error' => $e->getMessage(),
            ], 502);
        }

        $order->update(['mollie_payment_id' => $payment->id]);

        Log::channel('print')->info('Print order payment started.', [
            'order_id' => $order->id,
            'order_number' => $order->number,
            'mollie_payment_id' => $payment->id,
            'amount_minor' => $totalMinor,
            'currency' => $order->currency,
        ]);

        return new JsonResponse([
            'data' => $this->toPayload($order->load('items')),
            'checkout_url' => $payment->getCheckoutUrl(),
        ], 201);
    }

    /**
     * The Mollie status webhook URL, or null when the host is not publicly
     * reachable (localhost or a development TLD such as .test/.local). Mollie
     * rejects payments whose webhook URL it cannot reach, so omitting it keeps
     * local and preview checkouts working; production domains keep the webhook.
     */
    private function reachableWebhookUrl(): ?string
    {
        $url = URL::route('api.webhooks.print.mollie');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || preg_match('/\.(test|local|localhost|example|invalid)$/', $host) === 1;

        return $isLocal ? null : $url;
    }

    /**
     * Resolve the requested post/media pairs to storage paths, enforcing the
     * same visibility rule as the feed: the user must be allowed to view each
     * post, and only ready images can be printed.
     *
     * @param  array<int, array{post_id: string, media_id: string}>  $requested
     * @param  array{width: int, height: int}|null  $artworkDimensions  Resolved page size (mm); null falls back to the config box.
     * @return array<int, array{post_id: string, media_id: string, path: string, width: ?int, height: ?int}>
     */
    private function resolvePhotos(StorePrintOrderRequest $request, array $requested, ?array $artworkDimensions, string $appProduct): array
    {
        $media = PostMedia::query()
            ->with('post')
            ->whereIn('id', collect($requested)->pluck('media_id'))
            ->get()
            ->keyBy('id');

        // The page the photo is cover-cropped to fill, mirroring the generator:
        // the resolved artwork size, else the product's full-bleed config box.
        $pageBox = $artworkDimensions ?? PrintArtworkGenerator::fullBleedBoxMm($appProduct);
        $minDpi = (int) config('print.min_dpi', 150);

        return collect($requested)->map(function (array $photo) use ($request, $media, $pageBox, $minDpi, $appProduct): array {
            $item = $media->get($photo['media_id']);

            // Why a photo is unprintable, so production logs pinpoint a
            // recurring checkout failure instead of a generic 422.
            $reason = match (true) {
                $item === null => 'media_not_found',
                $item->post_id !== $photo['post_id'] => 'post_mismatch',
                $item->type !== 'image' => 'not_an_image',
                $item->status !== MediaStatus::Ready => 'not_ready',
                $request->user()->cannot('view', $item->post) => 'not_viewable',
                default => null,
            };

            if ($reason !== null) {
                Log::channel('print')->warning('Print order rejected: photo not printable.', [
                    'user_id' => $request->user()->id,
                    'post_id' => $photo['post_id'],
                    'media_id' => $photo['media_id'],
                    'reason' => $reason,
                    'media_status' => $item?->status?->value,
                ]);

                throw ValidationException::withMessages([
                    'items' => ['One or more photos are not available for printing.'],
                ]);
            }

            // Refuse a photo that can't meet the print-quality floor for the
            // chosen size: cover-cropping a too-small original to a large page
            // upscales it into a visibly soft, paid product.
            if ($pageBox !== null && $item->width !== null && $item->height !== null) {
                $sourceDpi = PrintArtworkGenerator::effectiveDpi(
                    $item->width,
                    $item->height,
                    (float) $pageBox['width'],
                    (float) $pageBox['height'],
                );

                if ($sourceDpi > 0 && $sourceDpi < $minDpi) {
                    Log::channel('print')->warning('Print order rejected: photo resolution too low for size.', [
                        'user_id' => $request->user()->id,
                        'media_id' => $item->id,
                        'app_product' => $appProduct,
                        'source_width' => $item->width,
                        'source_height' => $item->height,
                        'page_width_mm' => $pageBox['width'],
                        'page_height_mm' => $pageBox['height'],
                        'effective_dpi' => round($sourceDpi),
                        'min_dpi' => $minDpi,
                    ]);

                    throw ValidationException::withMessages([
                        'items' => ['One or more photos are not high enough resolution for the chosen size.'],
                    ]);
                }
            }

            return [
                'post_id' => $item->post_id,
                'media_id' => $item->id,
                // Print from the full-resolution original, not the 1920px
                // display rendition: at 300 DPI that variant only covers
                // ~16 cm. The dimensions are the EXIF-corrected display size,
                // which shares the original's aspect ratio and drives the
                // page-orientation choice without re-reading the file.
                'path' => $item->original_path ?? $item->path,
                'width' => $item->width,
                'height' => $item->height,
            ];
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toPayload(PrintOrder $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'status' => $order->status->value,
            'printdeal_order_number' => $order->printdeal_order_number,
            'printdeal_status' => $order->printdeal_status,
            'created_at' => $order->created_at?->toIso8601String(),
            'items' => $order->items->map(fn ($item): array => [
                'id' => $item->id,
                'app_product' => $item->app_product,
                'name' => $item->name,
                'options' => $item->options,
                'photo_count' => count($item->photos),
                'amount_minor' => $item->amount_minor,
                'printdeal_status' => $item->printdeal_status,
            ])->values()->all(),
        ];
    }
}
