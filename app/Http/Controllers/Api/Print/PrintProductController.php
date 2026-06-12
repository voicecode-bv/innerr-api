<?php

namespace App\Http\Controllers\Api\Print;

use App\Http\Controllers\Controller;
use App\Models\PrintdealProduct;
use Illuminate\Http\JsonResponse;

class PrintProductController extends Controller
{
    /**
     * The print-shop catalog as the app renders it. Photo-count limits come
     * from config/print.php (they are tied to the artwork layout); prices,
     * sizes, and availability come from the admin-managed offerings in the
     * `printdeal_products` table. An app product without an orderable
     * offering is shown as "coming soon".
     */
    public function __invoke(): JsonResponse
    {
        $offerings = PrintdealProduct::query()
            ->offered()
            ->orderByDesc('updated_at')
            ->get()
            ->unique('app_product')
            ->keyBy('app_product');

        $products = collect(config('print.products'))
            ->map(function (array $product, string $id) use ($offerings): array {
                $offering = $offerings->get($id);
                $priceMinor = $offering?->sellingPriceMinor();

                return [
                    'id' => $id,
                    'price_minor' => $priceMinor,
                    'currency' => $offering?->currency ?? 'EUR',
                    'min_photos' => $product['min_photos'],
                    'max_photos' => $product['max_photos'],
                    'sizes' => $offering?->sizes,
                    'available' => $offering !== null
                        && $priceMinor !== null
                        && $offering->isOrderable(),
                ];
            })
            ->values();

        return new JsonResponse([
            'data' => $products,
            'shipping_countries' => config('print.shipping_countries'),
            'return_url' => config('print.return_url'),
        ]);
    }
}
