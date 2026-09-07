<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        $this->rateLimiters();
    }

    /**
     * The webhook limit.
     *
     * Meta batches its deliveries and its real rate for one page's lead forms
     * is a handful a minute at most, so 120 is generous for the traffic that is
     * meant to arrive and still a ceiling on traffic that is not: the URL is
     * public, and every POST costs an HMAC over an attacker-supplied body.
     *
     * Keyed by IP rather than by provider, because the thing being limited is a
     * caller, not a platform — one misbehaving source must not use up the
     * budget Meta needs.
     *
     * A 429 is safe here in a way it would not be elsewhere: Meta treats
     * anything but a 2xx as a failed delivery and redelivers, so a throttled
     * lead is delayed rather than lost.
     */
    private function rateLimiters(): void
    {
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
