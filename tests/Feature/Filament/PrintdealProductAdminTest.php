<?php

use App\Filament\Resources\PrintdealProducts\Pages\EditPrintdealProduct;
use App\Filament\Resources\PrintdealProducts\Pages\ListPrintdealProducts;
use App\Models\PrintdealProduct;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('lists synced products', function () {
    $product = PrintdealProduct::factory()->create([
        'name' => ['nl-NL' => 'Mokken', 'en-EN' => 'Mugs'],
    ]);

    Livewire::test(ListPrintdealProducts::class)
        ->assertSuccessful()
        ->assertSee('Mokken')
        ->assertSee($product->sku);
});

it('searches products by name and sku, case-insensitively', function () {
    $mugs = PrintdealProduct::factory()->create([
        'sku' => 'sku-mugs-uuid',
        'name' => ['en-EN' => 'mugs'],
    ]);
    $posters = PrintdealProduct::factory()->create([
        'sku' => 'sku-posters-uuid',
        'name' => ['en-EN' => 'posters'],
    ]);

    Livewire::test(ListPrintdealProducts::class)
        ->searchTable('Mugs')
        ->assertCanSeeTableRecords([$mugs])
        ->assertCanNotSeeTableRecords([$posters])
        ->searchTable('SKU-POSTERS')
        ->assertCanSeeTableRecords([$posters])
        ->assertCanNotSeeTableRecords([$mugs]);
});

it('configures an offering through the edit page', function () {
    $product = PrintdealProduct::factory()->create();

    Livewire::test(EditPrintdealProduct::class, ['record' => $product->id])
        ->fillForm([
            'enabled' => true,
            'app_product' => 'tshirt',
            'order_attributes' => [
                ['attribute' => 'Style', 'value' => 'Basic'],
            ],
            'user_options' => [
                ['attribute' => 'Size', 'values' => ['S', 'M']],
            ],
            'margin_percent' => 40,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $product->refresh();

    expect($product->enabled)->toBeTrue()
        ->and($product->app_product)->toBe('tshirt')
        ->and($product->order_attributes)->toBe([
            ['attribute' => 'Style', 'value' => 'Basic'],
        ])
        ->and($product->user_options)->toBe([
            ['attribute' => 'Size', 'values' => ['S', 'M']],
        ])
        ->and($product->margin_percent)->toBe(40.0);
});
