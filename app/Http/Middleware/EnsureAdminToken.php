<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the request carries a valid Sanctum token that was issued to an
 * AdminUser and has the 'role:admin' ability.
 *
 * This intentionally does NOT use the standard Laravel auth guard so that
 * admin tokens and user tokens are completely separate — a regular user
 * token cannot be used to access admin endpoints, ever.
 */
class EnsureAdminToken
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

        // Verify the token belongs to an AdminUser model, not a regular User
        if ($pat->tokenable_type !== \App\Models\AdminUser::class) {
            return response()->json(['error' => 'Unauthorized — insufficient privileges'], 403);
        }

        // Verify the token has not expired
        $expiry = (int) config('sanctum.admin_token_expiry', 43200);
        if ($pat->created_at->addSeconds($expiry)->isPast()) {
            $pat->delete();
            return response()->json(['error' => 'Unauthorized — token expired'], 401);
        }

        // Verify the token has the admin ability
        if (! $pat->can('role:admin')) {
            return response()->json(['error' => 'Unauthorized — insufficient privileges'], 403);
        }

        // Attach the admin to the request for use in controllers
        $request->merge(['_admin' => $pat->tokenable]);
        $request->setUserResolver(fn () => $pat->tokenable);

        // Update last_used_at
        $pat->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
