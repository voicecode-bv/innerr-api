<?php

use App\Filament\Pages\PrintdealOrders;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());

    config()->set('services.printdeal', [
        'base_url' => 'https://api.printdeal.test',
        'webhook_base_url' => 'https://webhook.printdeal.test',
        'api_key' => 'key',
        'secret' => 'secret',
        'test_orders' => true,
        'webhook_token' => null,
    ]);
});

it('lists orders fetched from the Printdeal API', function () {
    Http::fake([
        'api.printdeal.test/orders*' => Http::response([
            ['id' => 'pd-1', 'number' => 'DDB2026000001', 'status' => 'Confirmed', 'lines' => [[], []]],
        ]),
    ]);
    Http::preventStrayRequests();

    Livewire::test(PrintdealOrders::class)
        ->assertSuccessful()
        ->assertSet('rows', fn (array $rows): bool => count($rows) === 1 && $rows[0]['number'] === 'DDB2026000001')
        ->assertSee('DDB2026000001');
});

it('sends the limit, offset and status filter to the API', function () {
    Http::fake(['api.printdeal.test/orders*' => Http::response([])]);
    Http::preventStrayRequests();

    Livewire::test(PrintdealOrders::class)
        ->set('statusFilter', 'Complete');

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'limit=50')
            && str_contains($request->url(), 'offset=0')
            && str_contains($request->url(), 'status=Complete');
    });
});

it('shows an error when the Printdeal API fails', function () {
    Http::fake(['api.printdeal.test/orders*' => Http::response(['message' => 'boom'], 500)]);
    Http::preventStrayRequests();

    Livewire::test(PrintdealOrders::class)
        ->assertSuccessful()
        ->assertSet('rows', [])
        ->assertSet('error', fn (?string $error): bool => $error !== null);
});
