<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    // Chạy sau khi response đã được gửi — không làm chậm request
    public function terminate(Request $request, Response $response): void
    {
        if ($user = $request->user('sanctum')) {
            $user->timestamps = false;
            $user->update(['last_seen_at' => now()]);
        }
    }
}
