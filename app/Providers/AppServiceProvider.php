<?php

namespace App\Providers;

use App\Events\SubscriptionStatusChanged;
use App\Listeners\InvalidateUserPlanCache;
use App\Models\Subscription;
use App\Observers\SubscriptionObserver;
use App\Services\Subscriptions\Apple\AppleJwsVerifier;
use App\Services\Subscriptions\Apple\AppStoreServerApi;
use App\Services\Subscriptions\Apple\AppStoreServerJwt;
use App\Services\Subscriptions\ChannelRegistry;
use App\Services\Subscriptions\Channels\AppleChannel;
use App\Services\Subscriptions\Channels\GoogleChannel;
use App\Services\Subscriptions\Channels\MollieChannel;
use App\Services\Subscriptions\Google\GoogleAccessTokenClient;
use App\Services\Subscriptions\Google\PlayDeveloperApi;
use App\Services\Subscriptions\Google\PubSubOidcVerifier;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use MatanYadaev\EloquentSpatial\EloquentSpatial;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use Mollie\Api\MollieApiClient;
use SocialiteProviders\Apple\AppleExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MollieApiClient::class, function (): MollieApiClient {
            $client = new MollieApiClient;
            $apiKey = (string) config('services.mollie.api_key');

            if ($apiKey !== '') {
                $client->setApiKey($apiKey);
            }

            return $client;
        });

        $this->app->singleton(AppleJwsVerifier::class, function (): AppleJwsVerifier {
            return new AppleJwsVerifier(
                rootCaPath: config('apple-iap.root_ca_path'),
                verifyChain: true,
            );
        });

        $this->app->singleton(AppStoreServerJwt::class, function (): AppStoreServerJwt {
            return new AppStoreServerJwt(
                issuerId: (string) config('apple-iap.issuer_id'),
                keyId: (string) config('apple-iap.key_id'),
                bundleId: (string) config('apple-iap.bundle_id'),
                privateKeyPath: (string) config('apple-iap.private_key_path'),
                ttlSeconds: (int) config('apple-iap.jwt_ttl', 1800),
            );
        });

        $this->app->singleton(AppStoreServerApi::class, function ($app): AppStoreServerApi {
            return new AppStoreServerApi(
                http: $app->make(HttpFactory::class),
                jwt: $app->make(AppStoreServerJwt::class),
                environment: (string) config('apple-iap.environment', 'sandbox'),
                baseUrls: (array) config('apple-iap.base_urls', []),
            );
        });

        $this->app->singleton(GoogleAccessTokenClient::class, function ($app): GoogleAccessTokenClient {
            return new GoogleAccessTokenClient(
                http: $app->make(HttpFactory::class),
                cache: $app->make(CacheRepository::class),
                serviceAccountPath: (string) config('google-play.service_account_path'),
                tokenUrl: (string) config('google-play.oauth_token_url'),
                scope: (string) config('google-play.oauth_scope'),
                ttl: (int) config('google-play.access_token_ttl', 3000),
            );
        });

        $this->app->singleton(PlayDeveloperApi::class, function ($app): PlayDeveloperApi {
            return new PlayDeveloperApi(
                http: $app->make(HttpFactory::class),
                tokens: $app->make(GoogleAccessTokenClient::class),
                packageName: (string) config('google-play.package_name'),
                baseUrl: (string) config('google-play.androidpublisher_base'),
            );
        });

        $this->app->singleton(PubSubOidcVerifier::class, function ($app): PubSubOidcVerifier {
            return new PubSubOidcVerifier(
                http: $app->make(HttpFactory::class),
                cache: $app->make(CacheRepository::class),
                jwksUrl: (string) config('google-play.jwks_url'),
                jwksCacheTtl: (int) config('google-play.jwks_cache_ttl', 3600),
                expectedAudience: config('google-play.pubsub_audience') ?: null,
                verifySignature: true,
            );
        });

        $this->app->singleton(ChannelRegistry::class, function ($app): ChannelRegistry {
            $registry = new ChannelRegistry;
            $registry->register(new AppleChannel(
                verifier: $app->make(AppleJwsVerifier::class),
                api: $app->make(AppStoreServerApi::class),
                environment: (string) config('apple-iap.environment', 'sandbox'),
            ));
            $registry->register(new GoogleChannel(
                api: $app->make(PlayDeveloperApi::class),
                oidc: $app->make(PubSubOidcVerifier::class),
            ));
            $registry->register(new MollieChannel(
                $app->make(MollieApiClient::class),
                config('services.mollie', []),
            ));

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
