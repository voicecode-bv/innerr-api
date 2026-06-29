<?php

namespace App\Filament\Widgets;

use App\Enums\OnboardingStep as OnboardingStepEnum;
use App\Enums\OnboardingStepOutcome;
use App\Models\OnboardingStep;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OnboardingFunnel extends StatsOverviewWidget
{
    protected ?string $heading = 'Onboarding funnel';

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $totalUsers = User::query()->count();

        // Per (step, outcome) tallies. A row exists the moment a screen is
        // reached, so the per-screen drop-off below is independent of the skip
        // branches that made the old chained-count funnel misleading.
        /** @var array<string, array<string, int>> $byStepOutcome */
        $byStepOutcome = [];

        OnboardingStep::query()
            ->selectRaw('step, outcome, COUNT(*) AS total')
            ->groupBy('step', 'outcome')
            ->get()
            ->each(function ($row) use (&$byStepOutcome): void {
                $byStepOutcome[$row->step->value][$row->outcome->value] = (int) $row->total;
            });

        $onboardedCount = User::query()->whereNotNull('onboarded_at')->count();

        $stats = [
            Stat::make('Signed up', (string) $totalUsers)
                ->description('Total user accounts')
                ->color('gray'),
        ];

        foreach (OnboardingStepEnum::cases() as $step) {
            $outcomes = $byStepOutcome[$step->value] ?? [];
            $completed = $outcomes[OnboardingStepOutcome::Completed->value] ?? 0;
            $skipped = $outcomes[OnboardingStepOutcome::Skipped->value] ?? 0;
            $reachedOnly = $outcomes[OnboardingStepOutcome::Reached->value] ?? 0;

            // Everyone who opened the screen, and the share of them who left it
            // without advancing — the real per-screen drop-off.
            $reached = $completed + $skipped + $reachedOnly;
            $abandonedPct = $reached > 0
                ? round($reachedOnly / $reached * 100, 1)
                : 0.0;

            $stats[] = Stat::make($step->label(), (string) $reached)
                ->description("reached · {$completed} done · {$skipped} skipped · {$abandonedPct}% abandoned")
                ->color($abandonedPct > 25 ? 'danger' : ($abandonedPct > 10 ? 'warning' : 'success'));
        }

        $onboardedPct = $totalUsers > 0
            ? round($onboardedCount / $totalUsers * 100, 1)
            : 0.0;

        $stats[] = Stat::make('Onboarded', (string) $onboardedCount)
            ->description("{$onboardedPct}% of signups completed onboarding")
            ->color('primary');

        return $stats;
    }
}
