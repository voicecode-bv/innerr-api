<?php

namespace App\Filament\Pages;

use App\Services\Printdeal\PrintdealClient;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Reconciliation view of the orders Printdeal itself knows about (GET /orders),
 * separate from the local PrintOrder records. Read-only: it surfaces what the
 * supplier has so an admin can spot orders that never synced back locally.
 */
class PrintdealOrders extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Printdeal orders';

    protected static ?string $slug = 'printdeal-orders';

    protected static ?string $title = 'Printdeal orders';

    protected string $view = 'filament.pages.printdeal-orders';

    /** Printdeal caps the page size at 50. */
    private const PER_PAGE = 50;

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    public ?string $statusFilter = null;

    public int $offset = 0;

    public ?string $error = null;

    public function mount(): void
    {
        $this->load();
    }

    public function updatedStatusFilter(): void
    {
        $this->offset = 0;
        $this->load();
    }

    public function nextPage(): void
    {
        // Only advance when the current page was full; a short page is the last.
        if (count($this->rows) < self::PER_PAGE) {
            return;
        }

        $this->offset += self::PER_PAGE;
        $this->load();
    }

    public function previousPage(): void
    {
        $this->offset = max(0, $this->offset - self::PER_PAGE);
        $this->load();
    }

    /**
     * @return array<int, string>
     */
    public function statusOptions(): array
    {
        return ['Open', 'Confirmed', 'Complete', 'Cancelled', 'test'];
    }

    public function load(): void
    {
        $this->error = null;

        try {
            $response = app(PrintdealClient::class)->orders(
                self::PER_PAGE,
                $this->offset,
                $this->statusFilter ?: null,
            );

            // The endpoint may return a bare list or wrap it; accept both.
            $list = $response['data'] ?? $response['orders'] ?? $response;
            $this->rows = is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
        } catch (Throwable $e) {
            $this->rows = [];
            $this->error = $e->getMessage();
        }
    }
}
