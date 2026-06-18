<?php

use App\Models\PrintdealProduct;

function puzzleWithBakedBleed(): PrintdealProduct
{
    return PrintdealProduct::factory()->create([
        'app_product' => 'puzzle',
        'artwork' => [
            'size_attribute' => 'Print Area',
            'sizes' => [
                ['value' => '90 x 60 cm', 'width' => 906, 'height' => 606],
                ['value' => '54 x 40 cm', 'width' => 546, 'height' => 406],
            ],
        ],
    ]);
}

it('requires an --app or --sku target', function () {
    $this->artisan('printdeal:rebase-artwork-bleed')
        ->assertFailed();
});

it('previews without writing on a dry run', function () {
    $puzzle = puzzleWithBakedBleed();

    $this->artisan('printdeal:rebase-artwork-bleed', ['--app' => ['puzzle']])
        ->assertSuccessful();

    // Unchanged: dry run only previews.
    expect($puzzle->fresh()->artwork['sizes'][0])->toMatchArray(['width' => 906, 'height' => 606]);
});

it('strips the baked bleed from the targeted product with --apply', function () {
    $puzzle = puzzleWithBakedBleed();

    $this->artisan('printdeal:rebase-artwork-bleed', ['--app' => ['puzzle'], '--apply' => true])
        ->assertSuccessful();

    $sizes = $puzzle->fresh()->artwork['sizes'];

    expect($sizes[0])->toMatchArray(['value' => '90 x 60 cm', 'width' => 900, 'height' => 600])
        ->and($sizes[1])->toMatchArray(['value' => '54 x 40 cm', 'width' => 540, 'height' => 400]);
});

it('leaves products outside the target untouched', function () {
    $puzzle = puzzleWithBakedBleed();
    // A canvas already on trim sizes must not be rebased.
    $canvas = PrintdealProduct::factory()->create([
        'app_product' => 'canvas',
        'artwork' => [
            'size_attribute' => 'Format',
            'sizes' => [['value' => '60 x 40 cm', 'width' => 600, 'height' => 400]],
        ],
    ]);

    $this->artisan('printdeal:rebase-artwork-bleed', ['--app' => ['puzzle'], '--apply' => true])
        ->assertSuccessful();

    expect($canvas->fresh()->artwork['sizes'][0])->toMatchArray(['width' => 600, 'height' => 400])
        ->and($puzzle->fresh()->artwork['sizes'][0]['width'])->toBe(900);
});
