<?php

namespace App\Providers;

use App\Support\Cart;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
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
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Share the live cart count with the header (runs on every header render,
        // not at boot — so the session is ready and guests get their own cart).
        View::composer('partials.header', function ($view) {
            $view->with('cartCount', Cart::count());
        });
    }
}