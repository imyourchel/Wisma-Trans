<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Percayai proxy (ngrok, hosting) agar https dan nama domain terbaca benar
        $middleware->trustProxies(at: '*');

        // Notifikasi pembayaran dari server Midtrans tidak membawa token CSRF
        $middleware->validateCsrfTokens(except: [
            'midtrans/notification',
        ]);

        $middleware->alias([
            'staf' => \App\Http\Middleware\PastikanStaf::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
