<?php

namespace App\Http\Controllers\Api\Print;

use App\Enums\MediaStatus;
use App\Enums\PrintOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePrintOrderRequest;
use App\Models\PostMedia;
use App\Models\PrintdealProduct;
use App\Models\PrintOrder;
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
        // wrong amount when no price can be determined.
        $itemAmounts = [];

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

            $itemAmounts[$index] = $amount;
        }

        $totalMinor = array_sum($itemAmounts);

        $order = DB::transaction(function () use ($request, $user, $offerings, $requestedItems, $itemAmounts, $totalMinor): PrintOrder {
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
                $artworkDimensions = $offering->artworkDimensions($item['options'] ?? []);

                $order->items()->create([
                    'app_product' => $offering->app_product,
                    'name' => $offering->name,
                    'printdeal_sku' => $offering->sku,
                    'printdeal_attributes' => $offering->order_attributes ?? [],
                    'options' => ($item['options'] ?? []) !== [] ? $item['options'] : null,
                    'artwork_width_mm' => $artworkDimensions['width'] ?? null,
                    'artwork_height_mm' => $artworkDimensions['height'] ?? null,
                    'photos' => $this->resolvePhotos($request, $item['photos']),
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
            'items' => collect($requestedItems)->map(function (array $item, int $index) use ($offerings, $itemAmounts): array {
                $offering = $offerings->get($item['offering_id']);
                $options = $item['options'] ?? [];
                $dimensions = $offering?->artworkDimensions($options);

                return [
                    'app_product' => $offering?->app_product,
                    'options' => $options !== [] ? $options : null,
                    'artwork_width_mm' => $dimensions['width'] ?? null,
                    'artwork_height_mm' => $dimensions['height'] ?? null,
                    'amount_minor' => $itemAmounts[$index] ?? null,
                ];
            })->all(),
        ]);

        // Remember the address for next time, but only on opt-in. Stored as the
        // same snapshot shape the order keeps, so checkout can prefill it.
        if ($request->boolean('save_address')) {
            $user->update(['shipping_address' => $request->shippingAddress()]);
        }

        try {
            $payment = $mollie->payments->create([
                'amount' => [
                    'currency' => $order->currency,
                    'value' => number_format($totalMinor / 100, 2, '.', ''),
                ],
                'description' => "innerr print order #{$order->number}",
                'redirectUrl' => $request->validated('redirect_url'),
                'webhookUrl' => URL::route('api.webhooks.print.mollie'),
                'metadata' => [
                    'kind' => 'print_order',
                    'print_order_id' => $order->id,
                    'user_id' => $user->id,
                ],
            ]);
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
     * Resolve the requested post/media pairs to storage paths, enforcing the
     * same visibility rule as the feed: the user must be allowed to view each
     * post, and only ready images can be printed.
     *
     * @param  array<int, array{post_id: string, media_id: string}>  $requested
     * @return array<int, array{post_id: string, media_id: string, path: string}>
     */
    private function resolvePhotos(StorePrintOrderRequest $request, array $requested): array
    {
        $media = PostMedia::query()
            ->with('post')
            ->whereIn('id', collect($requested)->pluck('media_id'))
            ->get()
            ->keyBy('id');

        return collect($requested)->map(function (array $photo) use ($request, $media): array {
            $item = $media->get($photo['media_id']);

            if (
                $item === null
                || $item->post_id !== $photo['post_id']
                || $item->type !== 'image'
                || $item->status !== MediaStatus::Ready
                || $request->user()->cannot('view', $item->post)
            ) {
                throw ValidationException::withMessages([
                    'items' => ['One or more photos are not available for printing.'],
                ]);
            }

            return [
                'post_id' => $item->post_id,
                'media_id' => $item->id,
                'path' => $item->path,
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
