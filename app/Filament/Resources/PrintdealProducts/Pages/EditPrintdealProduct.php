<?php

namespace App\Filament\Resources\PrintdealProducts\Pages;

use App\Filament\Resources\PrintdealProducts\PrintdealProductResource;
use App\Models\PrintdealProduct;
use App\Services\Printdeal\PrintdealProductSync;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;

class EditPrintdealProduct extends EditRecord
{
    protected static string $resource = PrintdealProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Fetch the attribute schema on first visit, so the order-attributes
     * form can suggest names and values without a map-save-sync roundtrip.
     */
    protected function afterFill(): void
    {
        /** @var PrintdealProduct $record */
        $record = $this->getRecord();

        if (! empty($record->attribute_schema) || $record->delisted_at !== null) {
            return;
        }

        try {
            app(PrintdealProductSync::class)->refreshAttributeSchema($record);
        } catch (\Throwable $e) {
            Log::warning("Printdeal schema fetch failed for {$record->sku}", [
                'message' => $e->getMessage(),
            ]);

            Notification::make()
                ->warning()
                ->title('Could not fetch the attribute schema from Printdeal')
                ->body('The form works, but without name/value suggestions. Check the API credentials or try again later.')
                ->send();
        }
    }

    /**
     * Refresh the purchase price right away once order attributes are
     * configured, instead of waiting for the next catalog sync.
     */
    protected function afterSave(): void
    {
        /** @var PrintdealProduct $record */
        $record = $this->getRecord();

        try {
            if (app(PrintdealProductSync::class)->refreshPurchasePrice($record)) {
                Notification::make()
                    ->success()
                    ->title('Purchase price refreshed: '.Number::currency($record->purchase_price_minor / 100, 'EUR', 'nl'))
                    ->send();
            }
        } catch (\Throwable $e) {
            Log::warning("Printdeal price refresh failed for {$record->sku}", [
                'message' => $e->getMessage(),
            ]);

            Notification::make()
                ->warning()
                ->title('Could not fetch the purchase price from Printdeal')
                ->body('Check that the order attributes form a valid combination, or run "Sync catalog" later.')
                ->send();
        }
    }
}
