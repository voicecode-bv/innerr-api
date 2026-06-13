<?php

use App\Models\PrintOrderItem;
use App\Services\Printdeal\PrintArtworkGenerator;
use Illuminate\Support\Facades\Storage;

/**
 * Store a solid JPEG of the given pixel size on the fake disk.
 */
function storeSizedPhoto(string $path, int $width, int $height): void
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 200, 120, 40));

    ob_start();
    imagejpeg($image);
    Storage::disk()->put($path, ob_get_clean());
    imagedestroy($image);
}

/**
 * Read the first page box from the raw PDF as [width, height] in points.
 */
function mediaBoxSize(string $pdf): array
{
    expect($pdf)->toMatch('/\/MediaBox/');

    preg_match('/\/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $m);

    return [(float) $m[3] - (float) $m[1], (float) $m[4] - (float) $m[2]];
}

function artworkFor(string $appProduct, string $path): string
{
    $item = PrintOrderItem::factory()->make([
        'app_product' => $appProduct,
        'photos' => [['post_id' => 'p1', 'media_id' => 'm1', 'path' => $path]],
    ]);

    return app(PrintArtworkGenerator::class)->generate($item);
}

beforeEach(function () {
    Storage::fake();
});

it('rotates an auto-orientation product to a portrait page for a portrait photo', function () {
    storeSizedPhoto('photos/portrait.jpg', 40, 60);

    [$width, $height] = mediaBoxSize(artworkFor('canvas', 'photos/portrait.jpg'));

    expect($width)->toBeLessThan($height);
});

it('keeps an auto-orientation product landscape for a landscape photo', function () {
    storeSizedPhoto('photos/landscape.jpg', 60, 40);

    [$width, $height] = mediaBoxSize(artworkFor('canvas', 'photos/landscape.jpg'));

    expect($width)->toBeGreaterThan($height);
});

it('leaves a fixed-orientation product portrait regardless of the photo', function () {
    storeSizedPhoto('photos/landscape.jpg', 60, 40);

    // The t-shirt chest print is a fixed A4-ish portrait area; a landscape
    // photo is cover-cropped into it rather than rotating the page.
    [$width, $height] = mediaBoxSize(artworkFor('tshirt', 'photos/landscape.jpg'));

    expect($width)->toBeLessThan($height);
});
