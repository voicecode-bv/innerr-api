<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Read-only viewer for the files in storage/logs. Built for large files: it
 * only ever reads the tail of the selected log (the most recent bytes) instead
 * of loading the whole file into memory, so a multi-megabyte laravel.log stays
 * cheap to inspect.
 */
class LogViewer extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Logs';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $slug = 'logs';

    protected static ?string $title = 'Log viewer';

    protected string $view = 'filament.pages.log-viewer';

    /** Never read more than this many bytes from the end of a log file. */
    private const MAX_BYTES = 524288; // 512 KB

    public ?string $file = null;

    public ?string $level = null;

    public string $search = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->admin === true;
    }

    public function mount(): void
    {
        $this->file = $this->files()[0]['name'] ?? null;
    }

    public function updatedFile(): void
    {
        $this->level = null;
        $this->search = '';
    }

    /**
     * Metadata for every log file in storage/logs, newest first.
     *
     * @return array<int, array{name: string, size: int, modified: int}>
     */
    public function files(): array
    {
        $files = glob(storage_path('logs').'/*.log') ?: [];

        $rows = array_map(static function (string $path): array {
            $info = new SplFileInfo($path);

            return [
                'name' => $info->getFilename(),
                'size' => $info->getSize(),
                'modified' => $info->getMTime(),
            ];
        }, $files);

        usort($rows, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $rows;
    }

    /**
     * Parsed entries for the selected file, newest first, after applying the
     * level and search filters.
     *
     * @return array<int, array{datetime: ?string, environment: ?string, level: string, message: string, context: string, truncated: bool}>
     */
    public function entries(): array
    {
        $path = $this->resolvePath($this->file);

        if ($path === null) {
            return [];
        }

        [$contents, $truncated] = $this->readTail($path);

        $entries = $this->parse($contents, $truncated);

        $level = $this->level;
        $search = trim($this->search);

        return array_values(array_filter($entries, static function (array $entry) use ($level, $search): bool {
            if ($level !== null && $entry['level'] !== $level) {
                return false;
            }

            if ($search !== '' && ! Str::contains($entry['message'].' '.$entry['context'], $search, ignoreCase: true)) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Distinct levels present in the visible tail, for the filter dropdown.
     *
     * @return array<int, string>
     */
    public function levelOptions(): array
    {
        return ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
    }

    public function download(): ?StreamedResponse
    {
        $path = $this->resolvePath($this->file);

        if ($path === null) {
            return null;
        }

        return response()->streamDownload(function () use ($path): void {
            $handle = fopen($path, 'rb');

            while (! feof($handle)) {
                echo fread($handle, 8192);
            }

            fclose($handle);
        }, basename($path), ['Content-Type' => 'text/plain']);
    }

    /**
     * Validate the selected filename and return its absolute path, or null when
     * it is missing or points outside storage/logs (path-traversal guard).
     */
    private function resolvePath(?string $file): ?string
    {
        if ($file === null || $file === '') {
            return null;
        }

        if (basename($file) !== $file) {
            return null;
        }

        $path = storage_path('logs').'/'.$file;

        return is_file($path) ? $path : null;
    }

    /**
     * Read at most MAX_BYTES from the end of the file.
     *
     * @return array{0: string, 1: bool} the contents and whether the file was truncated
     */
    private function readTail(string $path): array
    {
        $size = filesize($path);
        $offset = max(0, $size - self::MAX_BYTES);

        $handle = fopen($path, 'rb');

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $contents = stream_get_contents($handle);
        fclose($handle);

        return [$contents === false ? '' : $contents, $offset > 0];
    }

    /**
     * Split the raw log text into structured entries, newest first. A new entry
     * starts on every line beginning with a "[date time]" header; everything in
     * between (stack traces, JSON context) is attached to the current entry.
     *
     * @return array<int, array{datetime: ?string, environment: ?string, level: string, message: string, context: string, truncated: bool}>
     */
    private function parse(string $contents, bool $truncated): array
    {
        $pattern = '/^\[(?<datetime>\d{4}-\d{2}-\d{2}[ T][\d:.\-+]+)\]\s+(?<env>\w+)\.(?<level>\w+):\s?(?<message>.*)$/';

        $entries = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $contents) as $line) {
            if (preg_match($pattern, $line, $matches) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = [
                    'datetime' => $matches['datetime'],
                    'environment' => $matches['env'],
                    'level' => strtoupper($matches['level']),
                    'message' => $matches['message'],
                    'context' => '',
                    'truncated' => false,
                ];

                continue;
            }

            if ($current !== null) {
                $current['context'] .= ($current['context'] === '' ? '' : "\n").$line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        // When the tail started mid-file the first entry is almost certainly a
        // partial fragment of an older entry; flag it rather than mislead.
        if ($truncated && $entries !== []) {
            $entries[0]['truncated'] = true;
        }

        return array_reverse($entries);
    }

    public function formatDate(?string $datetime): string
    {
        if ($datetime === null) {
            return '—';
        }

        try {
            return Carbon::parse($datetime)->format('d-m-Y H:i:s');
        } catch (\Throwable) {
            return $datetime;
        }
    }
}
