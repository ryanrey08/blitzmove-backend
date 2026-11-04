<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: `permission:orders.update` or `permission:trips.view|dispatch.view`
 * (any of the listed permissions passes).
 */
class EnsureUserHasPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $any = collect($permissions)->flatMap(fn ($p) => explode('|', $p))->filter()->values()->all();

        if (! $user || ! $user->hasAnyPermission($any)) {
            return response()->json(['message' => 'You do not have permission to perform this action.'], 403);
        }

        return $next($request);
    }
}
