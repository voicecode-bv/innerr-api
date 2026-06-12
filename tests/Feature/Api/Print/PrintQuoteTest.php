<?php

use App\Models\PrintdealProduct;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
        'webhook_base_url' => 'https://webhook.printdeal.test',
        'api_key' => 'key',
        'secret' => 'secret',
        'test_orders' => true,
        'webhook_token' => null,
    ]);

    Sanctum::actingAs(User::factory()->create());
});

function makePuzzleOffering(array $overrides = []): PrintdealProduct
{
    return PrintdealProduct::factory()->offered('puzzle')->create([
        'fixed_price_minor' => null,
        'margin_percent' => 10,
        'purchase_price_minor' => 2220,
        'user_options' => [
            ['attribute' => 'Print Area', 'values' => ['28 x 19 cm (35 pcs)', '68 x 44 cm (1000 pcs)']],
        ],
        ...$overrides,
    ]);
}

it('quotes the live price for the chosen options with the margin applied', function () {
    $offering = makePuzzleOffering();

    Http::fake([
        'api.printdeal.test/products/*' => Http::response(['price' => 30.0]),
    ]);
    Http::preventStrayRequests();

    $this->postJson('/api/print/quote', [
        'offering_id' => $offering->id,
        'options' => ['Print Area' => '68 x 44 cm (1000 pcs)'],
    ])
        ->assertOk()
        // ceil(3000 * 1.10) = 3300
        ->assertJsonPath('data.price_minor', 3300)
        ->assertJsonPath('data.currency', 'EUR');

    Http::assertSent(function ($request) use ($offering): bool {
        $attributes = collect($request['attributes'])->keyBy('attribute');

        return str_contains($request->url(), $offering->sku)
            && $attributes['Print Area']['value'] === '68 x 44 cm (1000 pcs)'
            && $attributes['quantity']['value'] === '1';
    });
});

it('returns the fixed selling price without a live call', function () {
    $offering = makePuzzleOffering(['fixed_price_minor' => 3995]);

    Http::fake();
    Http::preventStrayRequests();

    $this->postJson('/api/print/quote', [
        'offering_id' => $offering->id,
        'options' => ['Print Area' => '28 x 19 cm (35 pcs)'],
    ])
        ->assertOk()
        ->assertJsonPath('data.price_minor', 3995);

    Http::assertNothingSent();
});

it('rejects invalid or missing options', function () {
    $offering = makePuzzleOffering();

    $this->postJson('/api/print/quote', [
        'offering_id' => $offering->id,
        'options' => ['Print Area' => 'nonsense'],
    ])->assertJsonValidationErrors('options.Print Area');

    $this->postJson('/api/print/quote', [
        'offering_id' => $offering->id,
    ])->assertJsonValidationErrors('options.Print Area');
});

it('refuses when no price can be determined for the configuration', function () {
    $offering = makePuzzleOffering();

    Http::fake([
        'api.printdeal.test/products/*' => Http::response(['message' => 'invalid combination'], 400),
    ]);

    $this->postJson('/api/print/quote', [
        'offering_id' => $offering->id,
        'options' => ['Print Area' => '68 x 44 cm (1000 pcs)'],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'price_unavailable');
});

it('refuses offerings that are not orderable', function () {
    $offering = makePuzzleOffering(['enabled' => false]);

    $this->postJson('/api/print/quote', [
        'offering_id' => $offering->id,
        'options' => ['Print Area' => '28 x 19 cm (35 pcs)'],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'product_unavailable');
});
