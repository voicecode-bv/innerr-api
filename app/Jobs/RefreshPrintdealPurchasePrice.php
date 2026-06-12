<?php

namespace App\Jobs;

use App\Models\PrintdealProduct;
use App\Services\Printdeal\PrintdealProductSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Refresh an offering's base purchase price (the lowest across all option
 * combinations) on the queue. Pricing a product with many option
 * combinations means dozens of Printdeal calls; running that inside the
 * admin's save request hits the web timeout, so the save only queues this.
 */
class RefreshPrintdealPurchasePrice implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60];

    // Stays under the queue connection's retry_after (90s); a retry after a
    // slow first pass is cheap because the quotes are cached.
    public int $timeout = 80;

    public function __construct(
        public PrintdealProduct $printdealProduct,
    ) {}

    public function handle(PrintdealProductSync $sync): void
    {
        $sync->refreshPurchasePrice($this->printdealProduct->fresh());
    }

    public function failed(?\Throwable $exception): void
    {
        Log::warning("Printdeal price refresh failed for {$this->printdealProduct->sku}", [
            'message' => $exception?->getMessage(),
        ]);
    }
}
