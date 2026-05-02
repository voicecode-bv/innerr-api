<x-filament-panels::page>
    <div class="space-y-8">
        <form wire:submit="startCheckout">
            {{ $this->checkoutForm }}
            <div class="mt-4 flex items-center gap-3">
                <x-filament::button type="submit">Start checkout</x-filament::button>
                @if ($lastCheckoutUrl)
                    <a href="{{ $lastCheckoutUrl }}" target="_blank" rel="noopener" class="text-sm text-primary-600 underline break-all">
                        Open Mollie checkout: {{ $lastCheckoutUrl }}
                    </a>
                @endif
            </div>
        </form>

        <form wire:submit="simulateMollieWebhook">
            {{ $this->mollieWebhookForm }}
            <div class="mt-4 flex items-center gap-3">
                <x-filament::button type="submit" color="gray">Simuleer Mollie webhook</x-filament::button>
                @if ($lastMollieResult)
                    <span class="text-sm text-gray-600 dark:text-gray-300">{{ $lastMollieResult }}</span>
                @endif
            </div>
        </form>

        <form wire:submit="verifyApple">
            {{ $this->appleVerifyForm }}
            <div class="mt-4 flex items-center gap-3">
                <x-filament::button type="submit" color="gray">Verify Apple transaction</x-filament::button>
                @if ($lastAppleVerifyResult)
                    <span class="text-sm text-gray-600 dark:text-gray-300 break-all">{{ $lastAppleVerifyResult }}</span>
                @endif
            </div>
        </form>

        <form wire:submit="simulateAppleWebhook">
            {{ $this->appleWebhookForm }}
            <div class="mt-4 flex items-center gap-3">
                <x-filament::button type="submit" color="gray">Simuleer Apple webhook</x-filament::button>
                @if ($lastAppleWebhookResult)
                    <span class="text-sm text-gray-600 dark:text-gray-300 break-all">{{ $lastAppleWebhookResult }}</span>
                @endif
            </div>
        </form>

        <form wire:submit="verifyGoogle">
            {{ $this->googleVerifyForm }}
            <div class="mt-4 flex items-center gap-3">
                <x-filament::button type="submit" color="gray">Verify Google purchase</x-filament::button>
                @if ($lastGoogleVerifyResult)
                    <span class="text-sm text-gray-600 dark:text-gray-300 break-all">{{ $lastGoogleVerifyResult }}</span>
                @endif
            </div>
        </form>

        <form wire:submit="simulateGoogleWebhook">
            {{ $this->googleWebhookForm }}
            <div class="mt-4 flex items-center gap-3">
                <x-filament::button type="submit" color="gray">Simuleer Google webhook</x-filament::button>
                @if ($lastGoogleWebhookResult)
                    <span class="text-sm text-gray-600 dark:text-gray-300 break-all">{{ $lastGoogleWebhookResult }}</span>
                @endif
            </div>
        </form>
    </div>
</x-filament-panels::page>
