<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Token based authentication for the mobile app, backed by Laravel Sanctum.
 */
class AuthController extends ApiController
{
    /**
     * Exchange credentials for a Sanctum personal access token.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User|null $user */
        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->can_login) {
            return $this->fail('Your account has not been allowed to sign in. Contact the administrator.', 403);
        }

        if ($user->status !== 'active') {
            return $this->fail('Your account is not active.', 403);
        }

        if ($user->school_id && $user->school && ! $user->school->is_active) {
            return $this->fail(sprintf('%s is currently suspended.', $user->school->name), 403);
        }

        return $this->issueToken($request, $user);
    }

    /**
     * Create the token and return the bootstrap payload the app needs.
     */
    public function issueToken(Request $request, User $user): JsonResponse
    {
        // Keep one active token per device so repeat logins stay predictable.
        $name = $request->input('device_name') ?: 'mobile';

        $user->tokens()->where('name', $name)->delete();

        $token = $user->createToken($name);

        return $this->ok([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->profilePayload($user),
        ], [
            'must_change_password' => (bool) $user->must_change_password,
        ]);
    }

    /**
     * Re-issue a token for an already authenticated session.
     */
    public function refresh(Request $request): JsonResponse
    {
        return $this->issueToken($request, $this->user($request));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok($this->profilePayload($this->user($request)));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->user($request)->currentAccessToken()?->delete();

        return $this->ok(['signed_out' => true]);
    }

    /**
     * Revoke every device token for the authenticated user.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->user($request)->tokens()->delete();

        return $this->ok(['signed_out_all_devices' => true]);
    }

    /**
     * List active devices so a user can spot and revoke unknown sessions.
     */
    public function devices(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        $tokens = $this->user($request)->tokens()
            ->orderByDesc('last_used_at')
            ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at'])
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $this->timestamped($token->last_used_at),
                'created_at' => $this->timestamped($token->created_at),
                'current' => $current !== null && (string) $token->id === (string) $current->getKey(),
            ])
            ->all();

        return $this->ok($tokens);
    }

    public function revokeDevice(Request $request, string $tokenId): JsonResponse
    {
        $token = $this->user($request)->tokens()->whereKey($tokenId)->first();

        abort_unless($token, 404);

        $token->delete();

        return $this->ok(['revoked' => true]);
    }
}