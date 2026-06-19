@php
    $files = $this->files();
    $entries = $this->entries();

    $levelColors = [
        'DEBUG' => 'text-gray-500 bg-gray-100 dark:bg-gray-800 dark:text-gray-300',
        'INFO' => 'text-info-700 bg-info-100 dark:bg-info-900/30 dark:text-info-300',
        'NOTICE' => 'text-info-700 bg-info-100 dark:bg-info-900/30 dark:text-info-300',
        'WARNING' => 'text-warning-700 bg-warning-100 dark:bg-warning-900/30 dark:text-warning-300',
        'ERROR' => 'text-danger-700 bg-danger-100 dark:bg-danger-900/30 dark:text-danger-300',
        'CRITICAL' => 'text-danger-700 bg-danger-100 dark:bg-danger-900/30 dark:text-danger-300',
        'ALERT' => 'text-danger-700 bg-danger-100 dark:bg-danger-900/30 dark:text-danger-300',
        'EMERGENCY' => 'text-danger-700 bg-danger-100 dark:bg-danger-900/30 dark:text-danger-300',
    ];
@endphp

<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-64">
                <label for="file" class="block text-sm font-medium mb-1">Log file</label>
                <select
                    id="file"
                    wire:model.live="file"
                    class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm"
                >
                    @forelse ($files as $logFile)
                        <option value="{{ $logFile['name'] }}">
                            {{ $logFile['name'] }} ({{ \Illuminate\Support\Number::fileSize($logFile['size']) }})
                        </option>
                    @empty
                        <option value="">No log files found</option>
                    @endforelse
                </select>
            </div>

            <div class="w-40">
                <label for="level" class="block text-sm font-medium mb-1">Level</label>
                <select
                    id="level"
                    wire:model.live="level"
                    class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm"
                >
                    <option value="">All levels</option>
                    @foreach ($this->levelOptions() as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex-1 min-w-48">
                <label for="search" class="block text-sm font-medium mb-1">Search</label>
                <input
                    id="search"
                    type="search"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Filter messages…"
                    class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm"
                />
            </div>

            <div class="flex items-center gap-2">
                <x-filament::button color="gray" wire:click="$refresh">
                    Refresh
                </x-filament::button>
                @if ($file)
                    <x-filament::button color="gray" wire:click="download">
                        Download
                    </x-filament::button>
                @endif
            </div>
        </div>

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Showing the most recent entries (up to the last 512&nbsp;KB of the file), newest first.
        </p>

        <div class="rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-200 dark:divide-gray-700">
            @forelse ($entries as $entry)
                <div class="px-4 py-3 text-sm">
                    @if ($entry['truncated'])
                        <p class="mb-2 text-xs italic text-gray-400">… start of this entry was truncated …</p>
                    @endif
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex rounded px-2 py-0.5 text-xs font-semibold {{ $levelColors[$entry['level']] ?? 'text-gray-500 bg-gray-100 dark:bg-gray-800' }}">
                            {{ $entry['level'] }}
                        </span>
                        <span class="font-mono text-xs text-gray-500 dark:text-gray-400">
                            {{ $this->formatDate($entry['datetime']) }}
                        </span>
                        @if ($entry['environment'])
                            <span class="font-mono text-xs text-gray-400">{{ $entry['environment'] }}</span>
                        @endif
                    </div>
                    <div class="mt-1 break-words whitespace-pre-wrap font-mono text-xs text-gray-800 dark:text-gray-200">{{ $entry['message'] }}</div>
                    @if (trim($entry['context']) !== '')
                        <details class="mt-1">
                            <summary class="cursor-pointer text-xs text-primary-600 dark:text-primary-400">Show context / stack trace</summary>
                            <pre class="mt-1 max-h-96 overflow-auto rounded bg-gray-50 dark:bg-gray-900 p-2 text-xs text-gray-600 dark:text-gray-400">{{ $entry['context'] }}</pre>
                        </details>
                    @endif
                </div>
            @empty
                <div class="px-4 py-10 text-center text-sm text-gray-500">
                    No log entries match the current filters.
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
