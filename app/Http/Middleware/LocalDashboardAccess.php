<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class LocalDashboardAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() || $this->isLocalLoopbackRequest($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        abort(Response::HTTP_FORBIDDEN);
    }

    private function isLocalLoopbackRequest(Request $request): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        foreach (['Forwarded', 'X-Forwarded-For', 'X-Real-IP'] as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        $remoteAddress = $request->server->get('REMOTE_ADDR');

        return is_string($remoteAddress)
            && IpUtils::checkIp($remoteAddress, ['127.0.0.0/8', '::1/128']);
    }
}
