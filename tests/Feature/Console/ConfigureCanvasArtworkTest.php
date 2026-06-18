<?php

use App\Models\PrintdealProduct;

function canvasNeedingArtwork(): PrintdealProduct
{
    return PrintdealProduct::factory()->create([
        'app_product' => 'canvas',
        'user_options' => [
            ['attribute' => 'Frame Thickness', 'values' => ['Premium Thickness (4.5 Cm)', 'Classic Thickness (2 Cm)']],
            ['attribute' => 'Format', 'values' => ['120 X 80 Cm', '60 X 40 Cm']],
        ],
        'artwork' => ['size_attribute' => null, 'sizes' => [], 'frame_attribute' => null, 'frames' => []],
    ]);
}

it('derives trim sizes and frame depths from the option values with --apply', function () {
    $canvas = canvasNeedingArtwork();

    $this->artisan('printdeal:configure-canvas-artwork', ['--apply' => true])
        ->assertSuccessful();

    $artwork = $canvas->fresh()->artwork;

    expect($artwork['size_attribute'])->toBe('Format')
        ->and($artwork['frame_attribute'])->toBe('Frame Thickness')
        ->and($artwork['sizes'])->toBe([
            ['value' => '120 X 80 Cm', 'width' => 1200, 'height' => 800],
            ['value' => '60 X 40 Cm', 'width' => 600, 'height' => 400],
        ])
        ->and($artwork['frames'])->toBe([
            ['value' => 'Premium Thickness (4.5 Cm)', 'depth' => 45],
            ['value' => 'Classic Thickness (2 Cm)', 'depth' => 20],
        ]);
});

it('produces the expected delivered size after the bleed is added', function () {
    $canvas = canvasNeedingArtwork();

    $this->artisan('printdeal:configure-canvas-artwork', ['--apply' => true])->assertSuccessful();

    // 120x80 trim + 2*45 frame + 2*3 bleed = 1296 x 896.
    expect($canvas->fresh()->artworkDimensions(['Format' => '120 X 80 Cm', 'Frame Thickness' => 'Premium Thickness (4.5 Cm)']))
        ->toBe(['width' => 1296, 'height' => 896]);
});

it('does not write on a dry run', function () {
    $canvas = canvasNeedingArtwork();

    $this->artisan('printdeal:configure-canvas-artwork')->assertSuccessful();

    expect($canvas->fresh()->artwork['sizes'])->toBe([]);
});
