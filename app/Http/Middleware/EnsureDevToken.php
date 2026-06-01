<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the request carries a valid Sanctum token that was issued to an
 * AdminUser with the 'role:developer' ability.
 *
 * Developer routes (analytics, commission data) use this middleware.
 * Owner tokens (role:admin) are explicitly rejected here — there is no
 * cross-access between the two admin roles.
 */
class EnsureDevToken
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

        // Must belong to an AdminUser, not a regular subscriber
        if ($pat->tokenable_type !== \App\Models\AdminUser::class) {
            return response()->json(['error' => 'Unauthorized — insufficient privileges'], 403);
        }

        // Check expiry (same window as admin tokens)
        $expiry = (int) config('sanctum.admin_token_expiry', 43200);
        if ($pat->created_at->addSeconds($expiry)->isPast()) {
            $pat->delete();
            return response()->json(['error' => 'Unauthorized — token expired'], 401);
        }

        // Must have developer ability — owner tokens are rejected
        if (! $pat->can('role:developer')) {
            return response()->json(['error' => 'Unauthorized — insufficient privileges'], 403);
        }

        $request->merge(['_admin' => $pat->tokenable]);
        $request->setUserResolver(fn () => $pat->tokenable);

        $pat->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
