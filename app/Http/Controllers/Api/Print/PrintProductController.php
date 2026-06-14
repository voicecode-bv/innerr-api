<?php

namespace App\Http\Controllers\Api\Print;

use App\Http\Controllers\Controller;
use App\Models\PrintdealProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintProductController extends Controller
{
    /**
     * The print-shop catalog: every enabled offering, so one app product
     * (e.g. t-shirt) can appear in multiple variants (basic, premium). Photo
     * limits come from config/print.php (tied to the artwork layout); name,
     * price, and user options come from the offering.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $products = config('print.products');

        $offerings = PrintdealProduct::query()
            ->offered()
            ->orderBy('app_product')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (PrintdealProduct $offering): bool => isset($products[$offering->app_product]))
            ->map(fn (PrintdealProduct $offering): array => [
                'id' => $offering->id,
                'app_product' => $offering->app_product,
                'name' => $offering->name,
                'price_minor' => $offering->sellingPriceMinor(),
                'currency' => $offering->currency,
                'min_photos' => $products[$offering->app_product]['min_photos'],
                'max_photos' => $products[$offering->app_product]['max_photos'],
                'user_options' => $offering->user_options ?? [],
                'available' => $offering->isOrderable(),
                // Trim size and orientation policy so the app can render a
                // mockup that matches the generated artwork exactly.
                'format' => [
                    'width' => $products[$offering->app_product]['pdf']['width'],
                    'height' => $products[$offering->app_product]['pdf']['height'],
                    'orientation' => $products[$offering->app_product]['pdf']['orientation'] ?? 'fixed',
                ],
                // Admin-configured artwork sizing (size and frame in mm), so the
                // app can compute the real PDF size for the chosen options and
                // warn when a photo is too low-resolution for it.
                'artwork' => $offering->artwork,
            ])
            ->values();

        return new JsonResponse([
            'data' => $offerings,
            'shipping_countries' => config('print.shipping_countries'),
            'return_url' => config('print.return_url'),
            // The user's saved address (if any) so checkout can prefill it.
            'saved_address' => $request->user()?->shipping_address,
        ]);
    }
}
