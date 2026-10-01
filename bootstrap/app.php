<?php

use App\Http\Middleware\EnsureSchoolModuleEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectInactiveSchoolUsers;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias(['school.module' => EnsureSchoolModuleEnabled::class]);
        $middleware->web(append: [
            RedirectInactiveSchoolUsers::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
