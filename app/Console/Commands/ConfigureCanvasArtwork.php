<?php

namespace App\Console\Commands;

use App\Models\PrintdealProduct;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Derive a product's artwork sizing from its existing user options instead of
 * typing 22 rows by hand. Sizes come from the size option's values (e.g.
 * "120 X 80 Cm" → 1200 × 800 mm trim) and frame depths from the frame option's
 * values (e.g. "Premium Thickness (4.5 Cm)" → 45 mm). The print bleed is NOT
 * baked in — artworkDimensions() adds frame and bleed in code.
 *
 * Dry-run by default; --apply writes. Safe to re-run: it always rebuilds the
 * sizing from the current option values rather than mutating existing numbers.
 */
#[Signature('printdeal:configure-canvas-artwork
    {--app=canvas : App product to configure (ignored when --sku is given)}
    {--sku=* : Specific SKU(s) to configure}
    {--size-attribute=Format : User option whose values carry the size}
    {--frame-attribute=Frame Thickness : User option whose values carry the frame (blank to skip)}
    {--apply : Write the changes (omit for a dry run)}')]
#[Description('Derive artwork sizes/frames for canvas-like products from their option values')]
class ConfigureCanvasArtwork extends Command
{
    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $sizeAttribute = (string) $this->option('size-attribute');
        $frameAttribute = trim((string) $this->option('frame-attribute'));
        /** @var array<int, string> $skus */
        $skus = $this->option('sku');

        $products = PrintdealProduct::query()
            ->when($skus !== [], fn ($query) => $query->whereIn('sku', $skus))
            ->when($skus === [], fn ($query) => $query->where('app_product', (string) $this->option('app')))
            ->get();

        if ($products->isEmpty()) {
            $this->warn('No matching products.');

            return self::SUCCESS;
        }

        $configured = 0;

        foreach ($products as $product) {
            $userOptions = collect($product->user_options ?? []);

            $sizeValues = $userOptions->firstWhere('attribute', $sizeAttribute)['values'] ?? null;

            if (! is_array($sizeValues) || $sizeValues === []) {
                $this->warn("{$product->sku}: no '{$sizeAttribute}' user option; skipped.");

                continue;
            }

            $sizes = $this->deriveSizes($sizeValues);

            if ($sizes === []) {
                $this->warn("{$product->sku}: no '{$sizeAttribute}' values parsed as a size; skipped.");

                continue;
            }

            $frameValues = $frameAttribute !== ''
                ? ($userOptions->firstWhere('attribute', $frameAttribute)['values'] ?? [])
                : [];
            $frames = $this->deriveFrames(is_array($frameValues) ? $frameValues : []);

            $this->line("<info>{$product->sku}</info> ({$product->app_product})");
            $this->table(
                ['Size value', 'Trim (mm)'],
                array_map(fn (array $s): array => [$s['value'], "{$s['width']} × {$s['height']}"], $sizes),
            );

            if ($frames !== []) {
                $this->table(
                    ['Frame value', 'Depth (mm)'],
                    array_map(fn (array $f): array => [$f['value'], (string) $f['depth']], $frames),
                );
            }

            if ($apply) {
                $product->artwork = [
                    'size_attribute' => $sizeAttribute,
                    'sizes' => $sizes,
                    'frame_attribute' => $frames !== [] ? $frameAttribute : null,
                    'frames' => $frames,
                ];
                $product->save();
            }

            $configured++;
        }

        $this->info(($apply ? 'Configured' : 'Would configure')." {$configured} product(s).");

        if (! $apply) {
            $this->comment('Dry run — re-run with --apply to write.');
        }

        return self::SUCCESS;
    }

    /**
     * Parse "120 X 80 Cm" style values into trim sizes in mm.
     *
     * @param  array<int, string>  $values
     * @return array<int, array{value: string, width: int, height: int}>
     */
    private function deriveSizes(array $values): array
    {
        $sizes = [];

        foreach ($values as $value) {
            if (preg_match('/(\d+)\s*[x×]\s*(\d+)/i', (string) $value, $match)) {
                $sizes[] = [
                    'value' => (string) $value,
                    'width' => ((int) $match[1]) * 10,
                    'height' => ((int) $match[2]) * 10,
                ];
            } else {
                $this->warn("  could not parse size '{$value}'; skipped.");
            }
        }

        return $sizes;
    }

    /**
     * Parse the cm figure out of "Premium Thickness (4.5 Cm)" into a mm depth.
     *
     * @param  array<int, string>  $values
     * @return array<int, array{value: string, depth: int}>
     */
    private function deriveFrames(array $values): array
    {
        $frames = [];

        foreach ($values as $value) {
            if (preg_match('/([\d]+(?:[.,]\d+)?)\s*cm/i', (string) $value, $match)) {
                $frames[] = [
                    'value' => (string) $value,
                    'depth' => (int) round(((float) str_replace(',', '.', $match[1])) * 10),
                ];
            } else {
                $this->warn("  could not parse frame depth '{$value}'; skipped.");
            }
        }

        return $frames;
    }
}
