<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the request carries a valid Sanctum token issued to a regular User.
 *
 * Used to protect endpoints that require a logged-in user (e.g. viewing own
 * subscriptions). Not currently applied broadly — most user-facing endpoints
 * accept the userId as a parameter matching the existing Node.js behaviour.
 */
class EnsureUserToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'Unauthorized — missing token'], 401);
        }

        $pat = PersonalAccessToken::findToken($token);

        if (! $pat) {
            return response()->json(['error' => 'Unauthorized — invalid token'], 401);
        }

        // Verify the token belongs to a User model
        if ($pat->tokenable_type !== \App\Models\User::class) {
            return response()->json(['error' => 'Unauthorized — invalid token'], 401);
        }

        // Verify expiry
        $expiry = (int) config('sanctum.user_token_expiry', 2592000);
        if ($pat->created_at->addSeconds($expiry)->isPast()) {
            $pat->delete();
            return response()->json(['error' => 'Unauthorized — token expired'], 401);
        }

        // Verify ability
        if (! $pat->can('role:user')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->setUserResolver(fn () => $pat->tokenable);
        $pat->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
