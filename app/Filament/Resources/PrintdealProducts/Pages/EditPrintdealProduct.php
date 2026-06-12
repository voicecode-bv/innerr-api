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
     * With the schema in hand, an unconfigured product gets its attribute
     * rows prefilled: one row per attribute, single-value attributes filled
     * in. Only the actual choices (packing, print area, ...) remain manual,
     * because those decide which product variant we sell at which cost.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PrintdealProduct $record */
        $record = $this->getRecord();

        if (empty($record->attribute_schema) && $record->delisted_at === null) {
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

        if (empty($data['order_attributes']) && ! empty($record->attribute_schema)) {
            $data['order_attributes'] = collect($record->attribute_schema)
                ->reject(fn (array $entry): bool => strtolower($entry['attribute']) === 'quantity')
                ->map(fn (array $entry): array => [
                    'attribute' => $entry['attribute'],
                    'value' => count($entry['values'] ?? []) === 1 ? $entry['values'][0] : '',
                ])
                ->values()
                ->all();
        }

        return $data;
    }

    /**
     * An attribute that is a customer choice (user option) must not also be
     * pinned as an order attribute: the order call would then send the
     * attribute twice with conflicting values. The user-option wins; the
     * prefilled order-attribute row is dropped silently because moving a
     * choice to the customer is exactly what the admin just expressed.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $userChoices = collect($data['user_options'] ?? [])
            ->pluck('attribute')
            ->map(strtolower(...));

        $data['order_attributes'] = collect($data['order_attributes'] ?? [])
            ->reject(fn (array $row): bool => $userChoices->contains(strtolower((string) $row['attribute'])))
            ->values()
            ->all();

        return $data;
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
