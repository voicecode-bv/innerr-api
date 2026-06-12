<?php

namespace App\Console\Commands;

use App\Models\PrintdealProduct;
use App\Services\Printdeal\PrintdealClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;

#[Signature('printdeal:product {sku : Printdeal product SKU, or the id of a row in printdeal_products}')]
#[Description('Show a Printdeal product\'s attributes and allowed values, to copy into the admin\'s order attributes')]
class ShowPrintdealProduct extends Command
{
    public function handle(PrintdealClient $printdeal): int
    {
        $sku = $this->resolveSku((string) $this->argument('sku'));

        try {
            $attributes = $printdeal->attributes($sku);
        } catch (RequestException $e) {
            if (in_array($e->response->status(), [400, 404], true)) {
                $this->error("Printdeal does not know a product with sku {$sku}.");
                $this->line('Use the SKU column from the admin (shown under the product name), not the edit-page URL id, and make sure the catalog sync has run.');

                return self::FAILURE;
            }

            throw $e;
        }

        $this->info($sku);
        $this->newLine();
        $this->line('Attributes (copy the exact names and one allowed value each into');
        $this->line('the admin form; size-like attributes go in the Sizes field instead):');

        foreach ($attributes as $attribute => $values) {
            if ($attribute === 'externals') {
                continue;
            }

            $this->newLine();
            $this->line("  <options=bold>{$attribute}</>");

            if (is_array($values) && array_is_list($values)) {
                foreach ($values as $value) {
                    $this->line("    - {$value}");
                }

                continue;
            }

            // Range attribute: free numeric input within bounds.
            if (is_array($values)) {
                $unit = $values['unitOfMeasure'] ?? '';
                $this->line(sprintf(
                    '    range %s to %s, steps of %s %s',
                    $values['minimum'] ?? '?',
                    $values['maximum'] ?? '?',
                    $values['increment'] ?? '?',
                    $unit,
                ));
            }
        }

        $externals = $attributes['externals'] ?? [];

        if (is_array($externals) && $externals !== []) {
            $this->newLine();
            $this->line('Free-input attributes (externals, see the API docs for their rules):');
            $this->line('  '.implode(', ', array_keys($externals)));
        }

        return self::SUCCESS;
    }

    /**
     * Accept both a real Printdeal SKU and the id of a local
     * printdeal_products row (the uuid visible in the admin edit URL), since
     * the two are easily confused.
     */
    private function resolveSku(string $input): string
    {
        // In v2 the catalog skus are uuids themselves, so a uuid that matches
        // a local row id resolves to that row's sku; anything else is passed
        // through as-is.
        if (! Str::isUuid($input)) {
            return $input;
        }

        $local = PrintdealProduct::query()->find($input);

        if ($local !== null) {
            $this->line("Resolved local product id to sku {$local->sku}.");

            return $local->sku;
        }

        return $input;
    }
}
