<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * GET  /api/users             — admin: list all users with subscriptions
 * POST /api/users             — public: register new user
 * POST /api/users/login       — public: login by phone + password
 * GET  /api/users/by-phone/:phone — public: lookup user by phone
 * DELETE /api/users/:id       — admin: delete user
 */
class UserController extends Controller
{
    /** Admin: list all users with their subscription summary */
    public function index(): JsonResponse
    {
        $users = User::with(['subscriptions' => function ($q) {
            $q->orderByDesc('created_at');
        }])->orderByDesc('id')->get();

        return response()->json($users);
    }

    /** Public: register a new user */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:200'],
            'phone'    => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        // Check uniqueness manually to return a clean error message
        if (User::where('phone', $data['phone'])->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['Phone number already registered.'],
            ]);
        }

        $user = User::create([
            'username'      => $data['username'],
            'phone'         => $data['phone'],
            'password_hash' => Hash::make($data['password']),
        ]);

        $token = $user->createToken('user-token', ['role:user']);

        return response()->json([
            'id'        => $user->id,
            'username'  => $user->username,
            'phone'     => $user->phone,
            'createdAt' => $user->created_at?->getTimestampMs(),
            'token'     => $token->plainTextToken,
        ], 201);
    }

    /** Public: login by phone + password */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('phone', $data['phone'])->first();

        // Use PHP's native password_verify() instead of Hash::check() because
        // existing users have $2b$ prefix hashes from Node.js bcryptjs.
        // Laravel's BcryptHasher::check() rejects $2b$ (only accepts $2y$/$2a$),
        // but PHP's password_verify() accepts $2b$ natively — same algorithm.
        $ok = $user && password_verify($data['password'], (string) $user->password_hash);

        if (! $ok) {
            throw ValidationException::withMessages([
                'phone' => ['Invalid credentials.'],
            ]);
        }

        // Rotate token on every login for security
        $user->tokens()->where('name', 'user-token')->delete();
        $token = $user->createToken('user-token', ['role:user']);

        return response()->json([
            'id'        => $user->id,
            'username'  => $user->username,
            'phone'     => $user->phone,
            'createdAt' => $user->created_at?->getTimestampMs(),
            'token'     => $token->plainTextToken,
        ]);
    }

    /** Public: lookup user by phone (returns subscriptions too) */
    public function findByPhone(Request $request, string $phone): JsonResponse
    {
        $user = User::where('phone', $phone)
            ->with(['subscriptions' => function ($q) {
                $q->orderByDesc('created_at');
            }])
            ->first();

        if (! $user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        return response()->json($user);
    }

    /** Admin: hard-delete user (subscriptions cascade via FK) */
    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }
}
