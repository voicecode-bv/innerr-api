<?php

namespace App\Filament\Resources\SubscriptionEvents;

use App\Filament\Resources\SubscriptionEvents\Pages\ListSubscriptionEvents;
use App\Filament\Resources\SubscriptionEvents\Pages\ViewSubscriptionEvent;
use App\Filament\Resources\SubscriptionEvents\Schemas\SubscriptionEventInfolist;
use App\Filament\Resources\SubscriptionEvents\Tables\SubscriptionEventsTable;
use App\Models\SubscriptionEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SubscriptionEventResource extends Resource
{
    protected static ?string $model = SubscriptionEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $recordTitleAttribute = 'external_event_id';

    protected static ?string $navigationLabel = 'Subscription events';

    public static function infolist(Schema $schema): Schema
    {
        return SubscriptionEventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubscriptionEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptionEvents::route('/'),
            'view' => ViewSubscriptionEvent::route('/{record}'),
        ];
    }
}
