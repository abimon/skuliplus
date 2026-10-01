<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * Show the one-time-code verification form.
     */
    public function create(Request $request): Response
    {
        $challenge = $request->session()->get('password_reset');
        abort_unless(is_array($challenge) && isset($challenge['channel'], $challenge['identity']), 403);

        return Inertia::render('auth/reset-password', [
            'channel' => $challenge['channel'],
            'identity' => $challenge['identity'],
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Verify the OTP and update the password.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'otp' => 'required|digits:6',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);
        $challenge = $request->session()->get('password_reset');
        if (! is_array($challenge) || ! isset($challenge['channel'], $challenge['identity'])) {
            return to_route('password.request')->withErrors(['otp' => 'Request a new verification code.']);
        }

        $channel = $challenge['channel'];
        $identity = $challenge['identity'];
        $cacheKey = 'password-reset-otp:'.hash('sha256', $channel.'|'.$identity);
        $rateKey = 'password-reset-verify:'.hash('sha256', $channel.'|'.$identity.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw ValidationException::withMessages(['otp' => 'Too many attempts. Request a new code and try again.']);
        }

        $reset = Cache::lock($cacheKey.':lock', 5)->block(3, function () use ($cacheKey, $data) {
            $stored = Cache::get($cacheKey);
            if (! is_array($stored) || ! Hash::check($data['otp'], $stored['otp_hash'] ?? '')) {
                return false;
            }

            $user = User::query()->find($stored['user_id'] ?? null);
            if (! $user || ! $user->can_login || $user->status !== 'active' || ! $user->password) {
                Cache::forget($cacheKey);

                return false;
            }

            $user->forceFill([
                'password' => Hash::make($data['password']),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
            Cache::forget($cacheKey);

            return true;
        });

        if (! $reset) {
            RateLimiter::hit($rateKey, 600);
            throw ValidationException::withMessages(['otp' => 'That code is invalid or expired. Request a new code if needed.']);
        }

        RateLimiter::clear($rateKey);
        $request->session()->forget('password_reset');

        return to_route('login')->with('status', 'Password updated. Sign in with your new password.');
    }
}
