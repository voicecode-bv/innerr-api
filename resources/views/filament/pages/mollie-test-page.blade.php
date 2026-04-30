<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit="startCheckout">
            {{ $this->checkoutForm }}

            <div class="mt-4 flex items-center gap-3">
                <x-filament::button type="submit">
                    Start checkout
                </x-filament::button>

                @if ($lastCheckoutUrl)
                    <a href="{{ $lastCheckoutUrl }}" target="_blank" rel="noopener" class="text-sm text-primary-600 underline break-all">
                        Open Mollie checkout: {{ $lastCheckoutUrl }}
                    </a>
                @endif
            </div>
        </form>

        <form wire:submit="simulateWebhook">
            {{ $this->webhookForm }}

            <div class="mt-4 flex items-center gap-3">
                <x-filament::button type="submit" color="gray">
                    Simuleer webhook
                </x-filament::button>

                @if ($lastWebhookResult)
                    <span class="text-sm text-gray-600 dark:text-gray-300">{{ $lastWebhookResult }}</span>
                @endif
            </div>
        </form>
    </div>
</x-filament-panels::page>
