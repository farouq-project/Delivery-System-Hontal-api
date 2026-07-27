<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockViewingModeWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE']) &&
            $request->user()?->role === 'super_admin' &&
            $request->has('merchant_id')
        ) {
            return response()->json(
                ['message' => 'Write operations are not permitted in merchant viewing mode.'],
                403
            );
        }

        return $next($request);
    }
}
