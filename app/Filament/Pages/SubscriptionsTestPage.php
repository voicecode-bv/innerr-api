<?php

namespace App\Filament\Pages;

use App\Enums\SubscriptionChannel;
use App\Enums\SubscriptionEventType;
use App\Jobs\Subscriptions\ProcessSubscriptionEvent;
use App\Models\Price;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Channels\AppleChannel;
use App\Services\Subscriptions\Channels\GoogleChannel;
use App\Services\Subscriptions\Dto\CreateCheckoutRequest;
use App\Services\Subscriptions\Dto\VerifyPurchaseRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SubscriptionsTestPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'subscriptions-test';

    protected string $view = 'filament.pages.subscriptions-test-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?string $navigationLabel = 'Subscriptions test';

    protected static ?string $title = 'Subscriptions test';

    protected static ?int $navigationSort = 90;

    /**
     * @var array<string, mixed>
     */
    public array $checkoutData = [];

    /**
     * @var array<string, mixed>
     */
    public array $mollieWebhookData = [];

    /**
     * @var array<string, mixed>
     */
    public array $appleVerifyData = [];

    /**
     * @var array<string, mixed>
     */
    public array $appleWebhookData = [];

    /**
     * @var array<string, mixed>
     */
    public array $googleVerifyData = [];

    /**
     * @var array<string, mixed>
     */
    public array $googleWebhookData = [];

    public ?string $lastCheckoutUrl = null;

    public ?string $lastMollieResult = null;

    public ?string $lastAppleVerifyResult = null;

    public ?string $lastAppleWebhookResult = null;

    public ?string $lastGoogleVerifyResult = null;

    public ?string $lastGoogleWebhookResult = null;

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
        return ['checkoutForm', 'mollieWebhookForm', 'appleVerifyForm', 'appleWebhookForm', 'googleVerifyForm', 'googleWebhookForm'];
    }

    public function checkoutForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Mollie — start test-checkout')
                    ->description('Selecteer een user en een actieve Mollie-prijs. Open de URL om te betalen.')
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

    public function mollieWebhookForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Mollie — simuleer webhook')
                    ->description('Plak een Mollie payment-id (tr_…). Maakt event-rij en dispatcht processor synchroon.')
                    ->schema([
                        TextInput::make('payment_id')
                            ->label('Payment ID')
                            ->required()
                            ->placeholder('tr_xxxxxxxx'),
                    ]),
            ])
            ->statePath('mollieWebhookData');
    }

    public function appleVerifyForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Apple — verify client transaction')
                    ->description('Plak een StoreKit 2 signedTransaction JWS namens een user.')
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
                        Textarea::make('signed_transaction')
                            ->label('signedTransaction (JWS)')
                            ->required()
                            ->rows(4)
                            ->autosize(false)
                            ->placeholder('eyJhbGciOiJFUzI1NiIsIng1YyI6Wy4uLl19...'),
                    ]),
            ])
            ->statePath('appleVerifyData');
    }

    public function appleWebhookForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Apple — simuleer Server Notification V2')
                    ->description('Plak een signedPayload JWS van Apple (ASN V2). Wordt geverifieerd, opgeslagen en synchroon verwerkt.')
                    ->schema([
                        Textarea::make('signed_payload')
                            ->label('signedPayload (JWS)')
                            ->required()
                            ->rows(4)
                            ->autosize(false),
                    ]),
            ])
            ->statePath('appleWebhookData');
    }

    public function googleVerifyForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Google — verify Play purchase token')
                    ->description('Plak een purchaseToken + product_id namens een user.')
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
                        TextInput::make('product_id')
                            ->label('Product ID')
                            ->required()
                            ->placeholder('plus_google_monthly'),
                        Textarea::make('purchase_token')
                            ->label('Purchase token')
                            ->required()
                            ->rows(3)
                            ->autosize(false),
                    ]),
            ])
            ->statePath('googleVerifyData');
    }

    public function googleWebhookForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Google — simuleer Pub/Sub RTDN')
                    ->description('Plak een Pub/Sub envelope JSON of alleen de inner RTDN data (base64 of plain JSON).')
                    ->schema([
                        Textarea::make('payload')
                            ->label('Payload (Pub/Sub envelope JSON, OR raw base64 RTDN data)')
                            ->required()
                            ->rows(6)
                            ->autosize(false),
                    ]),
            ])
            ->statePath('googleWebhookData');
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

            Notification::make()->title('Checkout aangemaakt')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Mollie checkout mislukt')->body($e->getMessage())->danger()->send();
        }
    }

    public function simulateMollieWebhook(): void
    {
        $data = $this->mollieWebhookForm->getState();
        $paymentId = trim((string) $data['payment_id']);

        $this->lastMollieResult = $this->dispatchSimulatedEvent(
            channel: SubscriptionChannel::Mollie,
            externalEventId: $paymentId,
            payload: ['raw_id' => $paymentId, 'received_via' => 'filament-test'],
            type: SubscriptionEventType::PriceChange,
        );
    }

    public function verifyApple(): void
    {
        $data = $this->appleVerifyForm->getState();
        $user = User::query()->findOrFail($data['user_id']);

        try {
            $registry = app(ChannelRegistry::class);
            /** @var AppleChannel $channel */
            $channel = $registry->for(SubscriptionChannel::Apple);

            $status = $channel->verifyClientPurchase(new VerifyPurchaseRequest(
                user: $user,
                token: trim((string) $data['signed_transaction']),
            ));

            $this->lastAppleVerifyResult = sprintf(
                'OK: subscription %s, status %s, product %s',
                $status->channelSubscriptionId,
                $status->status->value,
                $status->channelProductId ?? '—',
            );

            Notification::make()->title('Apple verify gelukt')->body($this->lastAppleVerifyResult)->success()->send();
        } catch (\Throwable $e) {
            $this->lastAppleVerifyResult = 'Error: '.$e->getMessage();
            Notification::make()->title('Apple verify mislukt')->body($e->getMessage())->danger()->send();
        }
    }

    public function simulateAppleWebhook(): void
    {
        $data = $this->appleWebhookForm->getState();
        $signedPayload = trim((string) $data['signed_payload']);

        try {
            $registry = app(ChannelRegistry::class);
            /** @var AppleChannel $channel */
            $channel = $registry->for(SubscriptionChannel::Apple);

            $request = Request::create('/test', 'POST', ['signedPayload' => $signedPayload]);
            $outcome = $channel->handleWebhook($request);
        } catch (\Throwable $e) {
            $this->lastAppleWebhookResult = 'Verify failed: '.$e->getMessage();
            Notification::make()->title('Apple webhook simulatie mislukt')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->lastAppleWebhookResult = $this->dispatchSimulatedEvent(
            channel: SubscriptionChannel::Apple,
            externalEventId: $outcome->externalEventId,
            payload: array_merge($outcome->payload, ['signedPayload' => $signedPayload]),
            type: $outcome->type,
            occurredAt: $outcome->occurredAt,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchSimulatedEvent(
        SubscriptionChannel $channel,
        string $externalEventId,
        array $payload,
        SubscriptionEventType $type,
        ?Carbon $occurredAt = null,
    ): string {
        if ($externalEventId === '') {
            Notification::make()->title('Ontbrekende event id')->danger()->send();

            return 'Missing external event id';
        }

        $existing = SubscriptionEvent::query()
            ->where('channel', $channel)
            ->where('external_event_id', $externalEventId)
            ->first();

        if ($existing) {
            Notification::make()->title('Al verwerkt')->warning()->send();

            return "Event #{$existing->id} (already processed)";
        }

        $event = SubscriptionEvent::query()->create([
            'channel' => $channel,
            'type' => $type,
            'external_event_id' => $externalEventId,
            'occurred_at' => $occurredAt,
            'received_at' => now(),
            'payload' => $payload,
        ]);

        try {
            ProcessSubscriptionEvent::dispatchSync($event->id);
            $event->refresh();
            $result = sprintf(
                'Event #%d processed: from=%s → to=%s%s',
                $event->id,
                $event->from_status?->value ?? '—',
                $event->to_status?->value ?? '—',
                $event->error ? "  (error: {$event->error})" : '',
            );
            Notification::make()->title('Webhook gesimuleerd')->body($result)->success()->send();

            return $result;
        } catch (\Throwable $e) {
            Notification::make()->title('Webhook simulatie mislukt')->body($e->getMessage())->danger()->send();

            return 'Error: '.$e->getMessage();
        }
    }

    /**
     * @return array<int, Action>
     */
    protected function getCheckoutFormActions(): array
    {
        return [Action::make('startCheckout')->label('Start checkout')->action('startCheckout')];
    }

    /**
     * @return array<int, Action>
     */
    protected function getMollieWebhookFormActions(): array
    {
        return [Action::make('simulateMollieWebhook')->label('Simuleer Mollie webhook')->action('simulateMollieWebhook')];
    }

    /**
     * @return array<int, Action>
     */
    protected function getAppleVerifyFormActions(): array
    {
        return [Action::make('verifyApple')->label('Verify Apple transaction')->action('verifyApple')];
    }

    /**
     * @return array<int, Action>
     */
    protected function getAppleWebhookFormActions(): array
    {
        return [Action::make('simulateAppleWebhook')->label('Simuleer Apple webhook')->action('simulateAppleWebhook')];
    }

    public function verifyGoogle(): void
    {
        $data = $this->googleVerifyForm->getState();
        $user = User::query()->findOrFail($data['user_id']);

        try {
            $registry = app(ChannelRegistry::class);
            /** @var GoogleChannel $channel */
            $channel = $registry->for(SubscriptionChannel::Google);

            $status = $channel->verifyClientPurchase(new VerifyPurchaseRequest(
                user: $user,
                token: trim((string) $data['purchase_token']),
                productId: trim((string) $data['product_id']),
            ));

            $this->lastGoogleVerifyResult = sprintf(
                'OK: subscription %s, status %s, product %s',
                $status->channelSubscriptionId,
                $status->status->value,
                $status->channelProductId ?? '—',
            );
            Notification::make()->title('Google verify gelukt')->body($this->lastGoogleVerifyResult)->success()->send();
        } catch (\Throwable $e) {
            $this->lastGoogleVerifyResult = 'Error: '.$e->getMessage();
            Notification::make()->title('Google verify mislukt')->body($e->getMessage())->danger()->send();
        }
    }

    public function simulateGoogleWebhook(): void
    {
        $data = $this->googleWebhookForm->getState();
        $raw = trim((string) $data['payload']);

        $envelope = $this->normalizeGooglePayload($raw);

        try {
            $registry = app(ChannelRegistry::class);
            /** @var GoogleChannel $channel */
            $channel = $registry->for(SubscriptionChannel::Google);

            $request = Request::create('/test', 'POST', $envelope);
            $outcome = $channel->handleWebhook($request);
        } catch (\Throwable $e) {
            $this->lastGoogleWebhookResult = 'Verify failed: '.$e->getMessage();
            Notification::make()->title('Google webhook simulatie mislukt')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->lastGoogleWebhookResult = $this->dispatchSimulatedEvent(
            channel: SubscriptionChannel::Google,
            externalEventId: $outcome->externalEventId,
            payload: $outcome->payload + ['channel_subscription_id' => $outcome->channelSubscriptionId],
            type: $outcome->type,
            occurredAt: $outcome->occurredAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeGooglePayload(string $raw): array
    {
        $decoded = json_decode($raw, true);

        if (is_array($decoded) && isset($decoded['message'])) {
            return $decoded;
        }

        if (is_array($decoded)) {
            return [
                'message' => [
                    'messageId' => 'manual-'.bin2hex(random_bytes(4)),
                    'data' => base64_encode(json_encode($decoded)),
                    'publishTime' => now()->toIso8601String(),
                ],
            ];
        }

        return [
            'message' => [
                'messageId' => 'manual-'.bin2hex(random_bytes(4)),
                'data' => $raw,
                'publishTime' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getGoogleVerifyFormActions(): array
    {
        return [Action::make('verifyGoogle')->label('Verify Google purchase')->action('verifyGoogle')];
    }

    /**
     * @return array<int, Action>
     */
    protected function getGoogleWebhookFormActions(): array
    {
        return [Action::make('simulateGoogleWebhook')->label('Simuleer Google webhook')->action('simulateGoogleWebhook')];
    }
}
