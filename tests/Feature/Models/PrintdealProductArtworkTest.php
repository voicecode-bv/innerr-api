<?php

use App\Models\PrintdealProduct;

it('returns null when no artwork sizing is configured', function () {
    $product = PrintdealProduct::factory()->make(['artwork' => null]);

    expect($product->artworkDimensions(['Formaat' => '90 x 60 cm']))->toBeNull();
});

it('maps a puzzle size option directly to the typed PDF dimensions', function () {
    $product = PrintdealProduct::factory()->make([
        'artwork' => [
            'size_attribute' => 'Formaat',
            'sizes' => [
                ['value' => '90 x 60 cm', 'width' => 906, 'height' => 606],
                ['value' => '50 x 70 cm', 'width' => 506, 'height' => 706],
            ],
        ],
    ]);

    expect($product->artworkDimensions(['Formaat' => '90 x 60 cm']))
        ->toBe(['width' => 906, 'height' => 606])
        ->and($product->artworkDimensions(['Formaat' => '50 x 70 cm']))
        ->toBe(['width' => 506, 'height' => 706]);
});

it('adds twice the frame depth for a canvas', function () {
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

    // 200 + 2*20 = 240
    expect($product->artworkDimensions(['Formaat' => '20 x 20 cm', 'Frame' => '2 cm']))
        ->toBe(['width' => 240, 'height' => 240])
        // 200 + 2*45 = 290
        ->and($product->artworkDimensions(['Formaat' => '20 x 20 cm', 'Frame' => '4,5 cm']))
        ->toBe(['width' => 290, 'height' => 290]);
});

it('uses the single fixed size when no size option is set', function () {
    $product = PrintdealProduct::factory()->make([
        'artwork' => [
            'sizes' => [['value' => null, 'width' => 906, 'height' => 606]],
        ],
    ]);

    expect($product->artworkDimensions([]))->toBe(['width' => 906, 'height' => 606]);
});

it('returns null when the chosen size value is not configured', function () {
    $product = PrintdealProduct::factory()->make([
        'artwork' => [
            'size_attribute' => 'Formaat',
            'sizes' => [['value' => '90 x 60 cm', 'width' => 906, 'height' => 606]],
        ],
    ]);

    expect($product->artworkDimensions(['Formaat' => '30 x 30 cm']))->toBeNull();
});
