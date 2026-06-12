<?php

namespace App\Http\Controllers\Api\Print;

use App\Http\Controllers\Controller;
use App\Models\PrintdealProduct;
use App\Services\Printdeal\PrintOfferingPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PrintQuoteController extends Controller
{
    /**
     * Price one configuration of an offering, so the app can show the exact
     * amount while the user picks options (puzzle size, packing, ...). The
     * order endpoint recomputes the same price server-side; this quote is
     * display-only.
     */
    public function __invoke(Request $request, PrintOfferingPricing $pricing): JsonResponse
    {
        $data = $request->validate([
            'offering_id' => ['required', 'uuid'],
            'options' => ['sometimes', 'array'],
            'options.*' => ['string', 'max:100'],
        ]);

        $offering = PrintdealProduct::query()->find($data['offering_id']);

        if ($offering === null || ! $offering->isOrderable()) {
            return new JsonResponse([
                'message' => 'This product is not available.',
                'error_code' => 'product_unavailable',
            ], 422);
        }

        $options = $data['options'] ?? [];
        $errors = $offering->optionErrors($options);

        if ($errors !== []) {
            throw ValidationException::withMessages(
                collect($errors)
                    ->mapWithKeys(fn (string $message, string $attribute): array => [
                        "options.{$attribute}" => [$message],
                    ])
                    ->all(),
            );
        }

        $priceMinor = $pricing->sellingPriceMinor($offering, $options);

        if ($priceMinor === null) {
            return new JsonResponse([
                'message' => 'No price is available for this configuration right now.',
                'error_code' => 'price_unavailable',
            ], 422);
        }

        return new JsonResponse([
            'data' => [
                'price_minor' => $priceMinor,
                'currency' => $offering->currency,
            ],
        ]);
    }
}
