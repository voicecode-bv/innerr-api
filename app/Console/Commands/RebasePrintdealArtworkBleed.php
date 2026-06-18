<?php

namespace App\Console\Commands;

use App\Models\PrintdealProduct;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * One-off: strip the print bleed that older configs baked into artwork sizes
 * by hand, now that PrintdealProduct::artworkDimensions() adds it in code.
 * Without it, those products would deliver oversized PDFs (the bleed twice).
 *
 * Targeted on purpose (by --app and/or --sku) and dry-run by default: products
 * configured under the new convention already hold trim sizes and must NOT be
 * rebased, so the operator picks exactly which ones still carry the baked bleed.
 * Run once per product — re-running would strip the bleed a second time.
 */
#[Signature('printdeal:rebase-artwork-bleed
    {--app=* : App product(s) to rebase, e.g. --app=puzzle}
    {--sku=* : SKU(s) to rebase}
    {--apply : Write the changes (omit for a dry run)}')]
#[Description('Strip the manually baked print bleed from configured artwork sizes (one-off migration)')]
class RebasePrintdealArtworkBleed extends Command
{
    public function handle(): int
    {
        $bleedPerDimension = 2 * (int) config('print.artwork_bleed_mm', 3);
        $apply = (bool) $this->option('apply');

        /** @var array<int, string> $apps */
        $apps = $this->option('app');
        /** @var array<int, string> $skus */
        $skus = $this->option('sku');

        if ($apps === [] && $skus === []) {
            $this->error('Specify at least one --app or --sku; refusing to touch every product.');

            return self::FAILURE;
        }

        $products = PrintdealProduct::query()
            ->where(function ($query) use ($apps, $skus): void {
                if ($apps !== []) {
                    $query->orWhereIn('app_product', $apps);
                }

                if ($skus !== []) {
                    $query->orWhereIn('sku', $skus);
                }
            })
            ->get();

        if ($products->isEmpty()) {
            $this->warn('No matching products.');

            return self::SUCCESS;
        }

        $rows = [];
        $changed = 0;

        foreach ($products as $product) {
            $artwork = $product->artwork ?? [];
            $sizes = $artwork['sizes'] ?? [];

            if (! is_array($sizes) || $sizes === []) {
                continue;
            }

            $productChanged = false;

            foreach ($sizes as $index => $size) {
                $width = (int) ($size['width'] ?? 0);
                $height = (int) ($size['height'] ?? 0);
                $newWidth = $width - $bleedPerDimension;
                $newHeight = $height - $bleedPerDimension;

                if ($newWidth <= 0 || $newHeight <= 0) {
                    $this->warn("Skipping {$product->sku} size '".($size['value'] ?? '—')."': result would be non-positive.");

                    continue;
                }

                $sizes[$index]['width'] = $newWidth;
                $sizes[$index]['height'] = $newHeight;
                $productChanged = true;

                $rows[] = [
                    $product->sku,
                    $size['value'] ?? '—',
                    "{$width} × {$height}",
                    "{$newWidth} × {$newHeight}",
                ];
            }

            if ($productChanged) {
                $changed++;

                if ($apply) {
                    $artwork['sizes'] = $sizes;
                    $product->artwork = $artwork;
                    $product->save();
                }
            }
        }

        $this->table(['SKU', 'Size', 'Before (mm)', 'After (mm)'], $rows);
        $this->info(($apply ? 'Rebased' : 'Would rebase')." {$changed} product(s) by {$bleedPerDimension} mm per dimension.");

        if (! $apply) {
            $this->comment('Dry run — re-run with --apply to write. Run once per product.');
        }

        return self::SUCCESS;
    }
}
