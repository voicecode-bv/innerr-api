<?php

namespace App\Http\Controllers\Api\Print;

use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Models\PostMedia;
use App\Models\PrintdealProduct;
use App\Services\Printdeal\PrintArtworkGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PrintProductController extends Controller
{
    /**
     * The print-shop catalog: every enabled offering, so one app product
     * (e.g. t-shirt) can appear in multiple variants (basic, premium). Photo
     * limits come from config/print.php (tied to the artwork layout); name,
     * price, and user options come from the offering.
     *
     * When the request names a photo via `media_id`, each offering is
     * annotated with which of its sizes that photo can print at the quality
     * floor, mirroring the checkout DPI gate so the app can grey out sizes the
     * photo is too low-resolution for instead of letting the order be refused.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $products = config('print.products');
        $minDpi = (int) config('print.min_dpi', 150);
        $photo = $this->resolvePrintPhoto($request);

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
                // Per-size resolution verdict for the named photo, or null when
                // no viewable photo is named — then the app shows every size and
                // checkout stays the backstop.
                'printability' => $photo === null
                    ? null
                    : $this->printability($offering, $photo['width'], $photo['height'], $minDpi),
            ])
            ->values();

        return new JsonResponse([
            'data' => $offerings,
            'shipping_countries' => config('print.shipping_countries'),
            'return_url' => config('print.return_url'),
            // The quality floor the printability verdicts are measured against,
            // so the app can phrase its own "resolution too low" copy.
            'min_dpi' => $minDpi,
            // The user's saved address (if any) so checkout can prefill it.
            'saved_address' => $request->user()?->shipping_address,
        ]);
    }

    /**
     * The photo the app is sizing a print for, when the request names one via
     * `media_id`: its display pixel dimensions, which share the printed
     * original's aspect ratio and so drive the same DPI estimate the checkout
     * uses. Null when no printable photo is named, the photo is not a ready
     * image, it carries no dimensions, or the user may not view it — the
     * catalog then carries no printability hint.
     *
     * @return array{width: int, height: int}|null
     */
    private function resolvePrintPhoto(Request $request): ?array
    {
        $mediaId = $request->query('media_id');

        if (! is_string($mediaId) || ! Str::isUuid($mediaId)) {
            return null;
        }

        $media = PostMedia::query()->with('post')->find($mediaId);

        if (
            $media === null
            || $media->type !== 'image'
            || $media->status !== MediaStatus::Ready
            || $media->width === null
            || $media->height === null
            || $request->user()->cannot('view', $media->post)
        ) {
            return null;
        }

        return ['width' => $media->width, 'height' => $media->height];
    }

    /**
     * Which of an offering's sizes the given photo can print at the quality
     * floor, mirroring {@see PrintOrderController}'s
     * checkout gate so the app never offers a size the order endpoint would
     * refuse. `sizes` carries one entry per selectable size (value null for a
     * single fixed size); `printable` is true when at least one size clears the
     * floor.
     *
     * @return array{printable: bool, sizes: list<array{value: ?string, effective_dpi: ?int, printable: bool}>}
     */
    private function printability(PrintdealProduct $offering, int $widthPx, int $heightPx, int $minDpi): array
    {
        $sizes = collect($offering->artworkSizeBoxes())
            ->map(function (array $box) use ($widthPx, $heightPx, $minDpi): array {
                $dpi = PrintArtworkGenerator::effectiveDpi($widthPx, $heightPx, $box['width'], $box['height']);

                return [
                    'value' => $box['value'],
                    'effective_dpi' => $dpi > 0 ? (int) round($dpi) : null,
                    // A dimension we can't read (dpi 0) is not a reason to hide
                    // a size; the checkout gate skips it too rather than reject.
                    'printable' => $dpi <= 0 || $dpi >= $minDpi,
                ];
            })
            ->all();

        return [
            'printable' => collect($sizes)->contains(fn (array $size): bool => $size['printable']),
            'sizes' => $sizes,
        ];
    }
}
