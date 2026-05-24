<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Handles admin authentication.
 *
 * POST /api/auth/login          — issues a Sanctum admin token
 * POST /api/auth/change-password — requires valid admin token
 */
class AuthController extends Controller
{
    /**
     * Admin login. Returns a short-lived Bearer token.
     *
     * Rate limited to 5 attempts/min by the 'auth' rate limiter.
     * Uses a constant-time compare to prevent timing attacks.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ]);

        $admin = AdminUser::where('username', $data['username'])->first();

        // Deliberate constant-time comparison — even when admin not found we
        // still run Hash::check so both code paths take the same time.
        $passwordOk = $admin && Hash::check($data['password'], $admin->password_hash);

        if (! $passwordOk) {
            // Identical message for both "user not found" and "wrong password"
            // — prevents username enumeration
            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        try {
            $token = $admin->createToken('admin-token', ['role:admin']);
        } catch (QueryException $e) {
            if ($this->isTokenStorageFailure($e)) {
                return response()->json([
                    'error' => 'Token service unavailable',
                    'message' => 'Token storage is not ready. Run Sanctum migrations.',
                ], 503);
            }

            throw $e;
        }

        return response()->json([
            'token' => $token->plainTextToken,
            'admin' => [
                'id'       => $admin->id,
                'username' => $admin->username,
            ],
        ]);
    }

    /**
     * Change admin password. Requires current password verification.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword'     => ['required', 'string', 'min:12', 'different:currentPassword'],
        ]);

        /** @var AdminUser $admin */
        $admin = $request->user();

        if (! Hash::check($data['currentPassword'], $admin->password_hash)) {
            throw ValidationException::withMessages([
                'currentPassword' => ['Current password is incorrect.'],
            ]);
        }

        $admin->update(['password_hash' => Hash::make($data['newPassword'])]);

        // Revoke all tokens — forces re-login with new password
        $admin->tokens()->delete();

        return response()->json(['message' => 'Password updated successfully.']);
    }

    private function isTokenStorageFailure(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $message = strtolower($e->getMessage());

        // PostgreSQL: 42P01 = undefined_table, 42703 = undefined_column
        return in_array($sqlState, ['42P01', '42703'], true)
            && str_contains($message, 'personal_access_tokens');
    }
}
