<?php

namespace App\Http\Controllers\Api\Print;

use App\Enums\MediaStatus;
use App\Enums\PrintOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePrintOrderRequest;
use App\Models\PostMedia;
use App\Models\PrintOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;

class PrintOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = PrintOrder::query()
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
     * Create a print order and its Mollie payment. The order stays
     * `pending_payment` until the Mollie webhook confirms; only then does the
     * SubmitPrintOrder job place it at Printdeal.
     */
    public function store(StorePrintOrderRequest $request, MollieApiClient $mollie): JsonResponse
    {
        $user = $request->user();
        $offering = $request->offering();
        $amountMinor = $offering?->sellingPriceMinor();

        if ($offering === null || $amountMinor === null || ! $offering->isOrderable()) {
            return new JsonResponse([
                'message' => 'This product is not available yet.',
                'error_code' => 'product_unavailable',
            ], 422);
        }

        $photos = $this->resolvePhotos($request);

        // Sku, attributes, and price are snapshotted: the admin can re-map or
        // re-price the offering later without affecting orders already placed.
        $order = PrintOrder::query()->create([
            'user_id' => $user->id,
            'product' => $request->validated('product'),
            'options' => $request->validated('options') ?: null,
            'photos' => $photos,
            'shipping_address' => $request->shippingAddress(),
            'printdeal_sku' => $offering->sku,
            'printdeal_attributes' => $offering->order_attributes,
            'amount_minor' => $amountMinor,
            'currency' => $offering->currency,
            'status' => PrintOrderStatus::PendingPayment,
        ]);

        try {
            $payment = $mollie->payments->create([
                'amount' => [
                    'currency' => $order->currency,
                    'value' => number_format($amountMinor / 100, 2, '.', ''),
                ],
                'description' => "innerr print order {$order->id}",
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

            return new JsonResponse([
                'message' => 'Could not start the payment.',
                'error' => $e->getMessage(),
            ], 502);
        }

        $order->update(['mollie_payment_id' => $payment->id]);

        return new JsonResponse([
            'data' => $this->toPayload($order),
            'checkout_url' => $payment->getCheckoutUrl(),
        ], 201);
    }

    /**
     * Resolve the requested post/media pairs to storage paths, enforcing the
     * same visibility rule as the feed: the user must be allowed to view each
     * post, and only ready images can be printed.
     *
     * @return array<int, array{post_id: string, media_id: string, path: string}>
     */
    private function resolvePhotos(StorePrintOrderRequest $request): array
    {
        $requested = $request->validated('photos');

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
                    'photos' => ['One or more photos are not available for printing.'],
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
            'product' => $order->product,
            'options' => $order->options,
            'photo_count' => count($order->photos),
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'status' => $order->status->value,
            'printdeal_order_number' => $order->printdeal_order_number,
            'printdeal_status' => $order->printdeal_status,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
