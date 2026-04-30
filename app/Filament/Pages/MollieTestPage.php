<?php

namespace App\Filament\Pages;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Jobs\Subscriptions\ProcessSubscriptionEvent;
use App\Models\Price;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Dto\CreateCheckoutRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class MollieTestPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'mollie-test';

    protected string $view = 'filament.pages.mollie-test-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Mollie test';

    protected static ?string $title = 'Mollie test';

    protected static ?int $navigationSort = 90;

    /**
     * @var array<string, mixed>
     */
    public array $checkoutData = [];

    /**
     * @var array<string, mixed>
     */
    public array $webhookData = [];

    public ?string $lastCheckoutUrl = null;

    public ?string $lastWebhookResult = null;

    public function mount(): void
    {
        $this->checkoutForm->fill([
            'redirect_url' => static::getUrl(),
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->id() === 1;
    }

    /**
     * @return array<int, string>
     */
    protected function getForms(): array
    {
        return ['checkoutForm', 'webhookForm'];
    }

    public function checkoutForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Start Mollie test-checkout')
                    ->description('Selecteer een user en een actieve Mollie-prijs. Open de URL om in Mollie test-modus te betalen.')
                    ->schema([
                        Select::make('user_id')
                            ->label('User')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()
                                ->where(fn ($q) => $q->where('email', 'ilike', "%{$search}%")->orWhere('username', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"))
                                ->limit(20)
                                ->get()
                                ->mapWithKeys(fn (User $u): array => [$u->id => "{$u->name} <{$u->email}>"])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->find($value)?->only(['name', 'email']) ? User::query()->find($value)->name.' <'.User::query()->find($value)->email.'>' : null),
                        Select::make('price_id')
                            ->label('Mollie price')
                            ->required()
                            ->options(fn (): array => Price::query()
                                ->with('plan')
                                ->where('channel', SubscriptionChannel::Mollie)
                                ->where('is_active', true)
                                ->get()
                                ->mapWithKeys(fn (Price $p): array => [
                                    $p->id => sprintf('%s — %s — %s %.2f', $p->plan?->name ?? '?', $p->interval?->value ?? '?', $p->currency, $p->amount_minor / 100),
                                ])
                                ->all()),
                        TextInput::make('redirect_url')
                            ->label('Redirect URL na betaling')
                            ->required()
                            ->url(),
                    ]),
            ])
            ->statePath('checkoutData');
    }

    public function webhookForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Simuleer Mollie webhook')
                    ->description('Plak een Mollie payment-id (tr_…). Maakt een subscription_event-rij en dispatcht de processor synchroon, zonder Mollie te laten POSTen.')
                    ->schema([
                        TextInput::make('payment_id')
                            ->label('Payment ID')
                            ->required()
                            ->placeholder('tr_xxxxxxxx')
                            ->helperText('Dit triggert de webhook flow alsof Mollie hem zelf had aangeleverd.'),
                    ]),
            ])
            ->statePath('webhookData');
    }

    public function startCheckout(): void
    {
        $data = $this->checkoutForm->getState();

        $user = User::query()->findOrFail($data['user_id']);
        $price = Price::query()->with('plan')->findOrFail($data['price_id']);

        try {
            $registry = app(ChannelRegistry::class);
            $channel = $registry->for(SubscriptionChannel::Mollie);

            $result = $channel->createCheckout(new CreateCheckoutRequest(
                user: $user,
                price: $price,
                redirectUrl: $data['redirect_url'],
            ));

            $this->lastCheckoutUrl = $result->checkoutUrl;

            Notification::make()
                ->title('Checkout aangemaakt')
                ->body('Open de URL om in Mollie test-modus te betalen.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Mollie checkout mislukt')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function simulateWebhook(): void
    {
        $data = $this->webhookForm->getState();
        $paymentId = trim((string) $data['payment_id']);

        $existing = SubscriptionEvent::query()
            ->where('channel', SubscriptionChannel::Mollie)
            ->where('external_event_id', $paymentId)
            ->first();

        if ($existing) {
            Notification::make()
                ->title('Al verwerkt')
                ->body("Event #{$existing->id} bestaat al voor deze payment id.")
                ->warning()
                ->send();
            $this->lastWebhookResult = "Event #{$existing->id} (already processed)";

            return;
        }

        $event = SubscriptionEvent::query()->create([
            'channel' => SubscriptionChannel::Mollie,
            'type' => SubscriptionEventType::PriceChange,
            'external_event_id' => $paymentId,
            'received_at' => now(),
            'payload' => ['raw_id' => $paymentId, 'received_via' => 'filament-test'],
        ]);

        try {
            ProcessSubscriptionEvent::dispatchSync($event->id);
            $event->refresh();
            $this->lastWebhookResult = sprintf(
                'Event #%d processed: from=%s → to=%s',
                $event->id,
                $event->from_status?->value ?? '—',
                $event->to_status?->value ?? '—',
            );
            Notification::make()
                ->title('Webhook gesimuleerd')
                ->body($this->lastWebhookResult)
                ->success()
                ->send();
        } catch (\Throwable $e) {
            $this->lastWebhookResult = 'Error: '.$e->getMessage();
            Notification::make()
                ->title('Webhook simulatie mislukt')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return array<int, Action>
     */
    protected function getCheckoutFormActions(): array
    {
        return [
            Action::make('startCheckout')
                ->label('Start checkout')
                ->action('startCheckout'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getWebhookFormActions(): array
    {
        return [
            Action::make('simulateWebhook')
                ->label('Simuleer webhook')
                ->action('simulateWebhook'),
        ];
    }
}
