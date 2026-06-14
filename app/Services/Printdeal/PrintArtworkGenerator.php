<?php

namespace App\Services\Printdeal;

use App\Models\PrintOrderItem;
use App\Support\MediaUrl;
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
    private const DPI = 300;

    private const MM_PER_INCH = 25.4;

    public function __construct(private PdfXConverter $pdfXConverter) {}

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

        // Full-bleed page box: trim size extended by the bleed on every edge.
        $pageWidth = $spec['width'] + 2 * $spec['bleed'];
        $pageHeight = $spec['height'] + 2 * $spec['bleed'];

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

            // Some products (puzzles) must be delivered as CMYK PDF/X-1a:2001;
            // the RGB document above is the input to that conversion.
            return ($spec['pdf_x1a'] ?? false)
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
                (int) round($pageWidthMm / self::MM_PER_INCH * self::DPI),
                (int) round($pageHeightMm / self::MM_PER_INCH * self::DPI),
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
