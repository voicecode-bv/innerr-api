<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('db:reset-sequences {--schema=public}')]
#[Description('Reset all PostgreSQL sequences to MAX(column) for their owning tables.')]
class ResetSequences extends Command
{
    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('This command only supports PostgreSQL.');

            return self::FAILURE;
        }

        $schema = $this->option('schema');

        $sequences = DB::select(<<<'SQL'
            SELECT
                n.nspname AS schema_name,
                s.relname AS seq_name,
                t.relname AS table_name,
                a.attname AS column_name
            FROM pg_class s
            JOIN pg_depend d ON d.objid = s.oid AND d.deptype IN ('a', 'i')
            JOIN pg_class t ON d.refobjid = t.oid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
            JOIN pg_namespace n ON n.oid = s.relnamespace
            WHERE s.relkind = 'S' AND n.nspname = ?
            ORDER BY t.relname
        SQL, [$schema]);

        if (empty($sequences)) {
            $this->info("No sequences found in schema '{$schema}'.");

            return self::SUCCESS;
        }

        $resetCount = 0;

        foreach ($sequences as $seq) {
            $qualifiedTable = "\"{$seq->schema_name}\".\"{$seq->table_name}\"";
            $qualifiedColumn = "\"{$seq->column_name}\"";
            $qualifiedSeq = "{$seq->schema_name}.{$seq->seq_name}";

            $maxValue = DB::scalar("SELECT COALESCE(MAX({$qualifiedColumn}), 0) FROM {$qualifiedTable}");

            if ($maxValue > 0) {
                DB::statement('SELECT setval(?, ?)', [$qualifiedSeq, $maxValue]);
                $this->line("  <fg=green>✓</> {$seq->seq_name} → {$maxValue}");
            } else {
                DB::statement('SELECT setval(?, 1, false)', [$qualifiedSeq]);
                $this->line("  <fg=yellow>○</> {$seq->seq_name} → 1 (empty table)");
            }

            $resetCount++;
        }

        $this->newLine();
        $this->info("Reset {$resetCount} sequence(s).");

        return self::SUCCESS;
    }
}
