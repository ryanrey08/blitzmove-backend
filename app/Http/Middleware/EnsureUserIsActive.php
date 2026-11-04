<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tokens of a deactivated, suspended or deleted account stop working on the
 * next request, even if one was missed when the status changed.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->canAuthenticate()) {
            $user->tokens()->delete();

            return response()->json(['message' => 'This account is no longer active. Contact your administrator.'], 401);
        }

        return $next($request);
    }
}
