<?php

namespace App\Filament\Widgets;

use App\Enums\OnboardingStep as OnboardingStepEnum;
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

        /** @var array<string, int> $countsByStep */
        $countsByStep = OnboardingStep::query()
            ->selectRaw('step, COUNT(*) AS total')
            ->groupBy('step')
            ->pluck('total', 'step')
            ->all();

        $onboardedCount = User::query()->whereNotNull('onboarded_at')->count();

        $stats = [
            Stat::make('Signed up', (string) $totalUsers)
                ->description('Total user accounts')
                ->color('gray'),
        ];

        $previousCount = $totalUsers;

        foreach (OnboardingStepEnum::cases() as $step) {
            $count = (int) ($countsByStep[$step->value] ?? 0);
            $sharePct = $totalUsers > 0
                ? round($count / $totalUsers * 100, 1)
                : 0.0;
            $dropPct = $previousCount > 0
                ? round(($previousCount - $count) / $previousCount * 100, 1)
                : 0.0;

            $stats[] = Stat::make($step->label(), (string) $count)
                ->description("{$sharePct}% of signups · {$dropPct}% drop-off")
                ->color($dropPct > 25 ? 'danger' : ($dropPct > 10 ? 'warning' : 'success'));

            $previousCount = $count;
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
