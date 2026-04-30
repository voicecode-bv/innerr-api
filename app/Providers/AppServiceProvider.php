<?php

namespace App\Providers;

use App\Events\SubscriptionStatusChanged;
use App\Listeners\InvalidateUserPlanCache;
use App\Models\Subscription;
use App\Observers\SubscriptionObserver;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Channels\AppleChannel;
use App\Services\Subscriptions\Channels\GoogleChannel;
use App\Services\Subscriptions\Channels\MollieChannel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use MatanYadaev\EloquentSpatial\EloquentSpatial;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use SocialiteProviders\Apple\AppleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRegistry::class, function ($app): ChannelRegistry {
            $registry = new ChannelRegistry;
            $registry->register(new AppleChannel);
            $registry->register(new GoogleChannel);
            $registry->register(new MollieChannel(config('services.mollie', [])));

            return $registry;
        });
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(SocialiteWasCalled::class, AppleExtendSocialite::class.'@handle');

        EloquentSpatial::setDefaultSrid(Srid::WGS84);

        $events->listen(SubscriptionStatusChanged::class, InvalidateUserPlanCache::class);
        Subscription::observe(SubscriptionObserver::class);
    }
}
