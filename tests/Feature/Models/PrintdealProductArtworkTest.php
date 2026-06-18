<?php

use App\Models\PrintdealProduct;

it('returns null when no artwork sizing is configured', function () {
    $product = PrintdealProduct::factory()->make(['artwork' => null]);

    expect($product->artworkDimensions(['Formaat' => '90 x 60 cm']))->toBeNull();
});

it('maps a puzzle size option to the trim size plus print bleed', function () {
    $product = PrintdealProduct::factory()->make([
        'artwork' => [
            'size_attribute' => 'Formaat',
            'sizes' => [
                ['value' => '90 x 60 cm', 'width' => 900, 'height' => 600],
                ['value' => '50 x 70 cm', 'width' => 500, 'height' => 700],
            ],
        ],
    ]);

    // Base trim + 2 * 3 mm bleed.
    expect($product->artworkDimensions(['Formaat' => '90 x 60 cm']))
        ->toBe(['width' => 906, 'height' => 606])
        ->and($product->artworkDimensions(['Formaat' => '50 x 70 cm']))
        ->toBe(['width' => 506, 'height' => 706]);
});

it('adds twice the frame depth and the bleed for a canvas', function () {
    $product = PrintdealProduct::factory()->make([
        'artwork' => [
            'size_attribute' => 'Formaat',
            'sizes' => [['value' => '20 x 20 cm', 'width' => 200, 'height' => 200]],
            'frame_attribute' => 'Frame',
            'frames' => [
                ['value' => '2 cm', 'depth' => 20],
                ['value' => '4,5 cm', 'depth' => 45],
            ],
        ],
    ]);

    // 200 + 2*20 frame + 2*3 bleed = 246
    expect($product->artworkDimensions(['Formaat' => '20 x 20 cm', 'Frame' => '2 cm']))
        ->toBe(['width' => 246, 'height' => 246])
        // 200 + 2*45 frame + 2*3 bleed = 296
        ->and($product->artworkDimensions(['Formaat' => '20 x 20 cm', 'Frame' => '4,5 cm']))
        ->toBe(['width' => 296, 'height' => 296]);
});

it('delivers a 120x80 canvas with a 4.5 cm frame as 1296 x 896 mm', function () {
    $product = PrintdealProduct::factory()->make([
        'artwork' => [
            'size_attribute' => 'Formaat',
            'sizes' => [['value' => '120 x 80 cm', 'width' => 1200, 'height' => 800]],
            'frame_attribute' => 'Frame',
            'frames' => [['value' => '4,5 cm', 'depth' => 45]],
        ],
    ]);

    // 1200 + 2*45 + 2*3 = 1296, 800 + 90 + 6 = 896.
    expect($product->artworkDimensions(['Formaat' => '120 x 80 cm', 'Frame' => '4,5 cm']))
        ->toBe(['width' => 1296, 'height' => 896]);
});

it('uses the single fixed size plus bleed when no size option is set', function () {
    $product = PrintdealProduct::factory()->make([
        'artwork' => [
            'sizes' => [['value' => null, 'width' => 900, 'height' => 600]],
        ],
    ]);

    expect($product->artworkDimensions([]))->toBe(['width' => 906, 'height' => 606]);
});

it('returns null when the chosen size value is not configured', function () {
    $product = PrintdealProduct::factory()->make([
        'artwork' => [
            'size_attribute' => 'Formaat',
            'sizes' => [['value' => '90 x 60 cm', 'width' => 900, 'height' => 600]],
        ],
    ]);

    expect($product->artworkDimensions(['Formaat' => '30 x 30 cm']))->toBeNull();
});
