<?php

namespace Karu\NpAuthentication;

use Illuminate\Support\ServiceProvider;

class NpAuthServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app['auth']->provider('npauth',function($app, array $config)
        {
            return new NpAuthUserProvider();
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {

    }
}
