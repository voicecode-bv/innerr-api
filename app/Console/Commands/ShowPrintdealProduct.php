<?php

namespace App\Console\Commands;

use App\Services\Printdeal\PrintdealClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('printdeal:product {sku : Printdeal product SKU (uuid)}')]
#[Description('Show a Printdeal product\'s attributes and allowed values, to copy into the admin\'s order attributes')]
class ShowPrintdealProduct extends Command
{
    public function handle(PrintdealClient $printdeal): int
    {
        $product = $printdeal->product($this->argument('sku'));

        $name = $product['name']['nl-NL']
            ?? $product['name']['en-EN']
            ?? $this->argument('sku');

        $this->info($name);
        $this->newLine();
        $this->line('Attributes (copy the exact names and one allowed value each into');
        $this->line('the admin form; size-like attributes go in the Sizes field instead):');

        foreach ($product['attributes'] ?? [] as $attribute) {
            $label = $attribute['nameTranslations']['en-EN'] ?? $attribute['name'];
            $this->newLine();
            $this->line("  <options=bold>{$attribute['name']}</> ({$label})");

            foreach ($attribute['values'] ?? [] as $value) {
                $valueLabel = $value['nameTranslations']['en-EN'] ?? '';
                $suffix = $valueLabel !== '' && $valueLabel !== $value['name']
                    ? " ({$valueLabel})"
                    : '';
                $this->line("    - {$value['name']}{$suffix}");
            }
        }

        $quantities = $product['quantities'] ?? [];

        if ($quantities !== []) {
            $this->newLine();
            $this->line('Orderable quantities: '.implode(', ', array_slice($quantities, 0, 15)));
        }

        return self::SUCCESS;
    }
}
