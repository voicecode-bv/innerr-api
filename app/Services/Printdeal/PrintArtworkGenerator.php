<?php

namespace App\Services\Printdeal;

use App\Models\PrintOrder;
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
 */
class PrintArtworkGenerator
{
    private const DPI = 300;

    private const MM_PER_INCH = 25.4;

    public function generate(PrintOrder $order): string
    {
        $spec = config("print.products.{$order->product}.pdf");

        if ($spec === null) {
            throw new RuntimeException("Unknown print product '{$order->product}'.");
        }

        $photos = $order->photos;

        if ($photos === []) {
            throw new RuntimeException("Print order {$order->id} has no photos.");
        }

        // Full-bleed page box: trim size extended by the bleed on every edge.
        $pageWidth = $spec['width'] + 2 * $spec['bleed'];
        $pageHeight = $spec['height'] + 2 * $spec['bleed'];

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

            return $pdf->Output('artwork.pdf', 'S');
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
}
