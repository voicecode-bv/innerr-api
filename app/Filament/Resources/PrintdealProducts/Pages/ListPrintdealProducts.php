<?php

namespace App\Filament\Resources\PrintdealProducts\Pages;

use App\Filament\Resources\PrintdealProducts\PrintdealProductResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;

class ListPrintdealProducts extends ListRecords
{
    protected static string $resource = PrintdealProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Manual sync next to the daily schedule, so a fresh mapping can
            // be priced immediately instead of waiting for tonight's run.
            Action::make('sync')
                ->label('Sync catalog')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    Artisan::call('printdeal:sync-products');

                    Notification::make()
                        ->title(trim(Artisan::output()))
                        ->success()
                        ->send();
                }),
        ];
    }
}
