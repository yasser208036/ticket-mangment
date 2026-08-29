<?php

namespace App\Providers;

use App\Services\MailSafety;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(6)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('write', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Mail must not be able to leave a developer's machine. The Mailer
        // dispatches this through until(), so a listener returning false would
        // cancel the message *silently* — the guard throws instead, and this
        // closure must therefore never return a value.
        Event::listen(MessageSending::class, function (): void {
            $this->app->make(MailSafety::class)->guardOutgoingMail();
        });
    }
}
