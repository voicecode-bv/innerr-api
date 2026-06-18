<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex items-end justify-between gap-3">
            <div class="max-w-xs">
                <label for="statusFilter" class="block text-sm font-medium mb-1">Status</label>
                <select
                    id="statusFilter"
                    wire:model.live="statusFilter"
                    class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm"
                >
                    <option value="">All statuses</option>
                    @foreach ($this->statusOptions() as $status)
                        <option value="{{ $status }}">{{ $status }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-center gap-2">
                <x-filament::button color="gray" wire:click="previousPage" :disabled="$offset === 0">
                    Previous
                </x-filament::button>
                <span class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $offset + 1 }}–{{ $offset + count($rows) }}
                </span>
                <x-filament::button color="gray" wire:click="nextPage" :disabled="count($rows) < 50">
                    Next
                </x-filament::button>
            </div>
        </div>

        @if ($error)
            <div class="rounded-lg border border-danger-300 bg-danger-50 dark:bg-danger-900/20 px-4 py-3 text-sm text-danger-700 dark:text-danger-300">
                Could not load orders from Printdeal: {{ $error }}
            </div>
        @endif

        <div class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-left">
                    <tr>
                        <th class="px-3 py-2 font-medium">Number</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Reference</th>
                        <th class="px-3 py-2 font-medium">Lines</th>
                        <th class="px-3 py-2 font-medium">Created</th>
                        <th class="px-3 py-2 font-medium">Id</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-3 py-2">{{ data_get($row, 'number') ?? '—' }}</td>
                            <td class="px-3 py-2">{{ data_get($row, 'status') ?? '—' }}</td>
                            <td class="px-3 py-2">{{ data_get($row, 'reference') ?? '—' }}</td>
                            <td class="px-3 py-2">{{ count((array) (data_get($row, 'lines') ?? data_get($row, 'orderLines') ?? [])) }}</td>
                            <td class="px-3 py-2">{{ data_get($row, 'createdAt') ?? data_get($row, 'created_at') ?? '—' }}</td>
                            <td class="px-3 py-2 font-mono text-xs break-all">
                                {{ data_get($row, 'id') ?? data_get($row, 'uuid') ?? data_get($row, 'orderUuid') ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-center text-gray-500">
                                @if (! $error)
                                    No orders for this filter.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
