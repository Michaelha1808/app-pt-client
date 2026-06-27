<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Chỉ cho phép user có role = 'admin' và đang active truy cập route quản trị.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin() || $user->status !== 'active') {
            return response()->json(['detail' => 'Không có quyền truy cập trang quản trị'], 403);
        }

        return $next($request);
    }
}
