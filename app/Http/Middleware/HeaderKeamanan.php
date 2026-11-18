<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HeaderKeamanan
{
    // Header pengaman untuk semua halaman web aplikasi.
    public function handle(Request $request, Closure $next): Response
    {
        $respons = $next($request);

        $respons->headers->set('X-Frame-Options', 'DENY');                  // tidak boleh dibuka di dalam iframe
        $respons->headers->set('X-Content-Type-Options', 'nosniff');
        $respons->headers->set('Referrer-Policy', 'same-origin');
        $respons->headers->set('Cache-Control', 'no-store, private');       // halaman keuangan jangan di-cache browser

        if ($request->isSecure()) {
            $respons->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $respons;
    }
}