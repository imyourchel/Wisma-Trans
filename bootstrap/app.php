<?php

use App\Http\Middleware\PastikanStaf;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            'staf' => PastikanStaf::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Endpoint fetch (kode kamar, pesan makanan, bayar, cek tagihan) tetap menerima JSON pada 403/419/429,
        // dengan pesan Indonesia di "pesan" (dibaca halaman) dan "message". Permintaan Livewire dibiarkan:
        // 403 tampil sebagai halaman error di modal Livewire, 419 ditangani resources/js/app.js.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return null;
            }
            $bawaan = ['', 'This action is unauthorized.', 'Forbidden', 'Invalid signature.', 'CSRF token mismatch.', 'Too Many Attempts.'];
            $pesan = match ($e->getStatusCode()) {
                403 => $e instanceof InvalidSignatureException
                    ? 'Tautan ini sudah kedaluwarsa. Minta tautan baru lewat WhatsApp.'
                    : (in_array($e->getMessage(), $bawaan, true) ? 'Halaman ini bukan untuk peran Anda.' : $e->getMessage()),
                419 => 'Sesi Anda sudah berakhir. Muat ulang halaman, lalu coba lagi.',
                429 => 'Terlalu banyak percobaan. Tunggu sebentar, lalu coba lagi.',
                default => null,
            };

            return $pesan === null ? null : response()->json(['message' => $pesan, 'pesan' => $pesan], $e->getStatusCode(), $e->getHeaders());
        });
    })->create();
