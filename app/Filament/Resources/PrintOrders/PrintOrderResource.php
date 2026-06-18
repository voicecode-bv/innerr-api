<?php

namespace App\Filament\Resources\PrintOrders;

use App\Enums\PrintOrderStatus;
use App\Filament\Resources\PrintOrders\Pages\ListPrintOrders;
use App\Filament\Resources\PrintOrders\Pages\ViewPrintOrder;
use App\Filament\Resources\PrintOrders\Schemas\PrintOrderInfolist;
use App\Filament\Resources\PrintOrders\Tables\PrintOrdersTable;
use App\Jobs\SubmitPrintOrder;
use App\Models\PrintOrder;
use App\Services\Printdeal\PrintdealClient;
use App\Services\Printdeal\PrintOrderDetailsUpdater;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Throwable;

class PrintOrderResource extends Resource
{
    protected static ?string $model = PrintOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Print orders';

    /** Orders are created by the app's checkout, never by hand. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return PrintOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrintOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrintOrders::route('/'),
            'view' => ViewPrintOrder::route('/{record}'),
        ];
    }

    /**
     * Pull the live status from Printdeal (GET /orders/{id}) and write it onto
     * the order and its items. Shared by the table row and the view page.
     */
    public static function refreshFromPrintdealAction(): Action
    {
        return Action::make('refreshFromPrintdeal')
            ->label('Refresh from Printdeal')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (PrintOrder $record): bool => $record->printdeal_order_id !== null)
            ->action(function (PrintOrder $record): void {
                try {
                    $details = app(PrintdealClient::class)->order($record->printdeal_order_id);
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Could not fetch from Printdeal')
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                app(PrintOrderDetailsUpdater::class)->apply($record, $details);

                Notification::make()
                    ->success()
                    ->title('Refreshed from Printdeal')
                    ->send();
            });
    }

    /**
     * Re-queue a failed order for submission. Only when it never reached
     * Printdeal: a placed order already has an id, and resubmitting would
     * duplicate it (refresh that one instead).
     */
    public static function resubmitAction(): Action
    {
        return Action::make('resubmit')
            ->label('Resubmit')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (PrintOrder $record): bool => $record->status === PrintOrderStatus::Failed)
            ->action(function (PrintOrder $record): void {
                if ($record->printdeal_order_id !== null) {
                    Notification::make()
                        ->danger()
                        ->title('Already placed at Printdeal')
                        ->body('Use "Refresh from Printdeal" instead; resubmitting would duplicate the order.')
                        ->send();

                    return;
                }

                $record->update(['status' => PrintOrderStatus::Paid]);
                SubmitPrintOrder::dispatch($record);

                Notification::make()
                    ->success()
                    ->title('Resubmission queued')
                    ->send();
            });
    }
}
