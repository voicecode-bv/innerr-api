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
            $product = $printdeal->product($sku);
        } catch (RequestException $e) {
            if ($e->response->status() === 404) {
                // The v3 beta serves some catalog skus on the validate
                // endpoint while their details endpoint 404s; an empty
                // selection makes validate enumerate the full schema.
                return $this->showViaValidate($printdeal, $sku);
            }

            throw $e;
        }

        $name = $product['name']['nl-NL']
            ?? $product['name']['en-EN']
            ?? $sku;

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

    /**
     * Fallback schema source: validate an empty selection and print the
     * `remainingOptions`, which list every attribute with its allowed values.
     */
    private function showViaValidate(PrintdealClient $printdeal, string $sku): int
    {
        try {
            $validation = $printdeal->validateSelection($sku, []);
        } catch (RequestException $e) {
            if (in_array($e->response->status(), [400, 404], true)) {
                $this->error("Printdeal does not know a product with sku {$sku} (details and validate both failed).");
                $this->line('Use the SKU column from the admin (shown under the product name), not the edit-page URL id, and make sure the catalog sync has run.');

                return self::FAILURE;
            }

            throw $e;
        }

        $this->info($sku);
        $this->newLine();
        $this->line('Details endpoint had no data; schema below comes from the validate');
        $this->line('endpoint. Copy the exact names and one allowed value each into the');
        $this->line('admin form; size-like attributes go in the Sizes field instead:');

        foreach ($validation['remainingOptions'] ?? [] as $option) {
            $this->newLine();
            $this->line("  <options=bold>{$option['attribute']}</>");

            foreach ($option['values'] ?? [] as $value) {
                $this->line("    - {$value}");
            }
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
        // The id column is a real uuid type; anything else would make the
        // lookup itself error out on Postgres.
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
