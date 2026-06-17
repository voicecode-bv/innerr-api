<?php

namespace App\Services\Printdeal;

use App\Models\PrintOrderItem;
use App\Support\MediaUrl;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Laravel\Facades\Image;
use RuntimeException;
use TCPDF;

/**
 * Builds the print-ready PDF for an order from the selected photos.
 *
 * Every product is described in config/print.php by its trim size and bleed
 * (mm) plus a page strategy: a fixed page count that cycles through the
 * photos (calendar: 12 months), or one page per photo (album). Photos are
 * cover-cropped to the full bleed box at 300 DPI so the artwork never shows
 * white edges after trimming.
 *
 * Products flagged with `pdf_x1a` in config/print.php (puzzles) are converted
 * to a CMYK PDF/X-1a:2001 file before returning, via {@see PdfXConverter}.
 */
class PrintArtworkGenerator
{
    private const MM_PER_INCH = 25.4;

    public function __construct(private PdfXConverter $pdfXConverter) {}

    /**
     * The full-bleed page size (mm) for a product: trim extended by the bleed
     * on every edge, the box every photo is cover-cropped to fill.
     *
     * @return array{width: float, height: float}|null
     */
    public static function fullBleedBoxMm(string $appProduct): ?array
    {
        $spec = config("print.products.{$appProduct}.pdf");

        if ($spec === null) {
            return null;
        }

        return [
            'width' => $spec['width'] + 2 * $spec['bleed'],
            'height' => $spec['height'] + 2 * $spec['bleed'],
        ];
    }

    /**
     * Convert a millimetre length to pixels at the configured print DPI.
     */
    private static function pixels(float $mm): int
    {
        return (int) round($mm / self::MM_PER_INCH * (int) config('print.dpi', 300));
    }

    public function generate(PrintOrderItem $item): string
    {
        $spec = config("print.products.{$item->app_product}.pdf");

        if ($spec === null) {
            throw new RuntimeException("Unknown print product '{$item->app_product}'.");
        }

        $photos = $item->photos;

        if ($photos === []) {
            throw new RuntimeException("Print order item {$item->id} has no photos.");
        }

        // Page size in mm: the dimensions snapshotted on the order item (the
        // admin-configured size plus margin for the chosen options) take
        // precedence; otherwise the full-bleed box from config/print.php.
        if ($item->artwork_width_mm !== null && $item->artwork_height_mm !== null) {
            $pageWidth = $item->artwork_width_mm;
            $pageHeight = $item->artwork_height_mm;
        } else {
            $box = self::fullBleedBoxMm($item->app_product);
            $pageWidth = $box['width'];
            $pageHeight = $box['height'];
        }

        // Products that can be produced either way (canvas, puzzle) follow the
        // photo: a portrait photo must never end up on a landscape page. The
        // physical size is unchanged — only width and height swap — and the
        // whole document keeps one page size, so the orientation is decided
        // once from the first photo.
        $photoLandscape = $this->photoIsLandscape($photos[0]['path']);

        if (
            ($spec['orientation'] ?? 'fixed') === 'auto'
            && $photoLandscape !== null
            && $photoLandscape !== ($pageWidth >= $pageHeight)
        ) {
            [$pageWidth, $pageHeight] = [$pageHeight, $pageWidth];
        }

        $pageCount = $spec['pages'] === 'per-photo'
            ? count($photos)
            : (int) $spec['pages'];

        $needsPdfX = (bool) ($spec['pdf_x1a'] ?? false);

        Log::channel('print')->info('PrintArtworkGenerator: rendering artwork.', [
            'item_id' => $item->id,
            'app_product' => $item->app_product,
            'page_width_mm' => round($pageWidth, 2),
            'page_height_mm' => round($pageHeight, 2),
            'orientation' => $pageWidth >= $pageHeight ? 'landscape' : 'portrait',
            'page_count' => $pageCount,
            'photo_count' => count($photos),
            'dpi' => (int) config('print.dpi', 300),
            'pdf_x1a' => $needsPdfX,
        ]);

        $pdf = new TCPDF(
            $pageWidth >= $pageHeight ? 'L' : 'P',
            'mm',
            [$pageWidth, $pageHeight],
        );
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setMargins(0, 0, 0);
        $pdf->setAutoPageBreak(false);

        $temporaryFiles = [];

        try {
            for ($page = 0; $page < $pageCount; $page++) {
                $photo = $photos[$page % count($photos)];
                $jpeg = $this->coverCroppedJpeg($photo['path'], $pageWidth, $pageHeight);
                $temporaryFiles[] = $jpeg;

                $pdf->AddPage();
                $pdf->Image($jpeg, 0, 0, $pageWidth, $pageHeight, 'JPEG');
            }

            $content = $pdf->Output('artwork.pdf', 'S');

            Log::channel('print')->info('PrintArtworkGenerator: RGB PDF rendered.', [
                'item_id' => $item->id,
                'app_product' => $item->app_product,
                'bytes' => strlen($content),
            ]);

            // Some products (puzzles) must be delivered as CMYK PDF/X-1a:2001;
            // the RGB document above is the input to that conversion.
            return $needsPdfX
                ? $this->pdfXConverter->toPdfX1a($content)
                : $content;
        } finally {
            foreach ($temporaryFiles as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Cover-crop a stored photo to the page's aspect ratio at print
     * resolution and return the path of a temporary JPEG.
     */
    private function coverCroppedJpeg(string $path, float $pageWidthMm, float $pageHeightMm): string
    {
        $disk = MediaUrl::disk();

        if (! $disk->exists($path)) {
            throw new RuntimeException("Print photo not found on disk: {$path}");
        }

        $source = tempnam(sys_get_temp_dir(), 'print-src-');
        file_put_contents($source, $disk->get($path));

        $target = tempnam(sys_get_temp_dir(), 'print-page-').'.jpg';

        try {
            $image = Image::decodePath($source);
            $image->cover(
                self::pixels($pageWidthMm),
                self::pixels($pageHeightMm),
            );
            $image->save($target, quality: 92);
        } finally {
            @unlink($source);
        }

        return $target;
    }

    /**
     * Whether the stored photo is landscape (wider than tall). Returns null
     * when the dimensions can't be read, so the caller keeps the product's
     * natural orientation rather than flipping on a bad read. The print
     * rendition's pixels are already EXIF-oriented, so the raw dimensions
     * match how the photo is displayed.
     */
    private function photoIsLandscape(string $path): ?bool
    {
        $disk = MediaUrl::disk();

        if (! $disk->exists($path)) {
            throw new RuntimeException("Print photo not found on disk: {$path}");
        }

        $info = @getimagesizefromstring((string) $disk->get($path));

        if ($info === false || ($info[0] ?? 0) === 0 || ($info[1] ?? 0) === 0) {
            return null;
        }

        return $info[0] >= $info[1];
    }
}
