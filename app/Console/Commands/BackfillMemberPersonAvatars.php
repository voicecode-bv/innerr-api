<?php

namespace App\Console\Commands;

use App\Models\Person;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('people:backfill-member-avatars {--chunk=500 : Number of member-persons to process per chunk} {--dry-run : Report what would change without writing}')]
#[Description('Re-sync every member-Person\'s avatar from its linked user account. Fixes member-persons whose avatar is missing or stale because the user set or changed their avatar after the Person row was created.')]
class BackfillMemberPersonAvatars extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');

        $query = Person::query()
            ->whereNotNull('user_id')
            ->with('user:id,avatar,avatar_thumbnail');

        $total = $query->clone()->count();

        if ($total === 0) {
            $this->info('No member-persons to sync.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Syncing avatars for {$total} member-persons...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $unchanged = 0;
        $orphaned = 0;

        $query->chunkById($chunk, function ($people) use ($dryRun, $bar, &$updated, &$unchanged, &$orphaned) {
            foreach ($people as $person) {
                $user = $person->user;

                if ($user === null) {
                    $orphaned++;
                    $bar->advance();

                    continue;
                }

                if ($person->avatar === $user->avatar && $person->avatar_thumbnail === $user->avatar_thumbnail) {
                    $unchanged++;
                    $bar->advance();

                    continue;
                }

                if (! $dryRun) {
                    $person->updateQuietly([
                        'avatar' => $user->avatar,
                        'avatar_thumbnail' => $user->avatar_thumbnail,
                    ]);
                }

                $updated++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Member-person avatars — updated: {$updated}, already in sync: {$unchanged}");

        if ($orphaned > 0) {
            $this->warn("Member-persons with a missing linked user (skipped): {$orphaned}");
        }

        return self::SUCCESS;
    }
}
