<?php

use App\Models\PrintOrderItem;
use App\Services\Printdeal\PdfXConverter;
use App\Services\Printdeal\PrintArtworkGenerator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;

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

it('converts a puzzle to PDF/X-1a (CMYK) before returning it', function () {
    storeSizedPhoto('photos/puzzle.jpg', 60, 40);

    // The conversion itself is covered by PdfXConverterTest; here we only
    // assert the generator hands the rendered RGB PDF to it and returns its
    // result for the flagged product.
    $this->mock(PdfXConverter::class)
        ->shouldReceive('toPdfX1a')
        ->once()
        ->with(Mockery::on(fn (string $pdf): bool => str_starts_with($pdf, '%PDF')))
        ->andReturn('CONVERTED-PDFX');

    expect(artworkFor('puzzle', 'photos/puzzle.jpg'))->toBe('CONVERTED-PDFX');
});

it('renders at the artwork dimensions snapshotted on the order item', function () {
    storeSizedPhoto('photos/square.jpg', 50, 50);

    $item = PrintOrderItem::factory()->make([
        'app_product' => 'canvas',
        'artwork_width_mm' => 240,
        'artwork_height_mm' => 240,
        'photos' => [['post_id' => 'p1', 'media_id' => 'm1', 'path' => 'photos/square.jpg']],
    ]);

    [$width, $height] = mediaBoxSize(app(PrintArtworkGenerator::class)->generate($item));

    // 240 mm at 72 pt/inch is about 680 pt; both edges match the snapshot.
    $expected = 240 / 25.4 * 72;

    expect($width)->toBeGreaterThan($expected - 2)->toBeLessThan($expected + 2)
        ->and($height)->toBeGreaterThan($expected - 2)->toBeLessThan($expected + 2);
});

it('logs the chosen options and snapshotted dimensions when rendering', function () {
    // A portrait photo on a portrait page so orientation isn't swapped and the
    // logged dimensions match the snapshot exactly.
    storeSizedPhoto('photos/portrait.jpg', 40, 60);

    $handler = new TestHandler;
    Log::forgetChannel('print');
    config(['logging.channels.print' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    Log::channel('print')->getLogger()->setHandlers([$handler]);

    $item = PrintOrderItem::factory()->make([
        'app_product' => 'canvas',
        'options' => ['frame' => 'oak', 'size' => '40x60'],
        'artwork_width_mm' => 360,
        'artwork_height_mm' => 460,
        'photos' => [['post_id' => 'p1', 'media_id' => 'm1', 'path' => 'photos/portrait.jpg']],
    ]);

    app(PrintArtworkGenerator::class)->generate($item);

    $record = collect($handler->getRecords())
        ->first(fn ($r): bool => str_contains($r['message'], 'rendering artwork'));

    expect($record)->not->toBeNull()
        ->and($record['context']['options'])->toBe(['frame' => 'oak', 'size' => '40x60'])
        ->and($record['context']['dimension_source'])->toBe('snapshot')
        ->and($record['context']['page_width_mm'])->toBe(360.0)
        ->and($record['context']['page_height_mm'])->toBe(460.0);
});

it('orients an auto product from the snapshotted dimensions, not the file pixels', function () {
    // The stored photo is square (pixel read would be landscape), but the
    // snapshot says portrait — the printed original shares that aspect, so the
    // page must follow the snapshot and end up portrait.
    storeSizedPhoto('photos/square.jpg', 50, 50);

    $item = PrintOrderItem::factory()->make([
        'app_product' => 'canvas',
        'photos' => [[
            'post_id' => 'p1',
            'media_id' => 'm1',
            'path' => 'photos/square.jpg',
            'width' => 40,
            'height' => 60,
        ]],
    ]);

    [$width, $height] = mediaBoxSize(app(PrintArtworkGenerator::class)->generate($item));

    expect($width)->toBeLessThan($height);
});

it('computes the effective DPI a source delivers for a page, orientation-aligned', function () {
    // 4000x3000 px on a 600x400 mm page (23.62 x 15.75 in), long-to-long:
    // min(4000/23.62, 3000/15.75) = min(169, 190) = ~169 DPI. Orientation of
    // the source must not matter — a portrait source gives the same value.
    expect(round(PrintArtworkGenerator::effectiveDpi(4000, 3000, 600, 400)))->toBe(169.0)
        ->and(round(PrintArtworkGenerator::effectiveDpi(3000, 4000, 600, 400)))->toBe(169.0)
        // Missing dimensions yield 0.0 so the caller skips the check.
        ->and(PrintArtworkGenerator::effectiveDpi(0, 3000, 600, 400))->toBe(0.0);
});

it('does not run the PDF/X conversion for products that do not need it', function () {
    storeSizedPhoto('photos/canvas.jpg', 60, 40);

    $this->mock(PdfXConverter::class)
        ->shouldReceive('toPdfX1a')
        ->never();

    expect(artworkFor('canvas', 'photos/canvas.jpg'))->toStartWith('%PDF');
});
