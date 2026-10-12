<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        // Links gerados (e-mails de redefinição, sitemap, canonical) usam sempre o host de APP_URL, nunca o cabeçalho Host.
        URL::forceRootUrl(config('app.url'));
        // A paginação monta o caminho com request->url(), que traz o host da requisição; aqui passa pela raiz fixa.
        Paginator::currentPathResolver(fn () => URL::to(request()->getPathInfo()));

        RateLimiter::for('candidaturas', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
    }
}
