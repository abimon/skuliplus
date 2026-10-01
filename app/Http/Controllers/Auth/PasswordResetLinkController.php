<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordResetOtpNotification;
use App\Services\SmsOtpSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Show the one-time-code request page.
     */
    /** Show the one-time-code request page. */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Send a short-lived verification code to the selected contact channel.
     *
     * @throws ValidationException
     */
    /** Send a short-lived verification code to the selected contact channel. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'channel' => 'required|in:email,sms',
            'identity' => 'required|string|max:255',
        ]);
        $channel = $data['channel'];
        if ($channel === 'email' && ! filter_var(trim($data['identity']), FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['identity' => 'Enter a valid email address.']);
        }

        $identity = $channel === 'email' ? mb_strtolower(trim($data['identity'])) : $this->normalizeKenyanPhone($data['identity']);

        if ($identity === null) {
            throw ValidationException::withMessages(['identity' => 'Enter a valid Kenyan mobile number.']);
        }

        if ($channel === 'sms') {
            app(SmsOtpSender::class)->assertConfigured();
        }

        $requestLimit = 'password-reset-request:'.hash('sha256', $channel.'|'.$identity.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($requestLimit, 3)) {
            throw ValidationException::withMessages(['identity' => 'Please wait before requesting another code.']);
        }
        RateLimiter::hit($requestLimit, 60);

        $user = $channel === 'email'
            ? User::query()->whereRaw('LOWER(email) = ?', [$identity])->first()
            : $this->userByPhone($identity);

        if ($user && $user->can_login && $user->status === 'active' && $user->password) {
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            if ($channel === 'email') {
                Notification::sendNow($user, new PasswordResetOtpNotification($otp));
            } else {
                app(SmsOtpSender::class)->send($identity, $otp);
            }

            Cache::put($this->challengeKey($channel, $identity), [
                'user_id' => $user->id,
                'otp_hash' => Hash::make($otp),
            ], now()->addMinutes(10));
        }

        $request->session()->put('password_reset', ['channel' => $channel, 'identity' => $identity]);

        return to_route('password.reset')->with('status', 'If an active account matches, a verification code has been sent.');
    }

    private function normalizeKenyanPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $digits = '254'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '254'.$digits;
        } elseif (! str_starts_with($digits, '254')) {
            return null;
        }

        return preg_match('/^254[17]\d{8}$/', $digits) ? '+'.$digits : null;
    }

    private function userByPhone(string $normalizedPhone): ?User
    {
        $suffix = substr($normalizedPhone, -9);

        $matches = User::query()
            ->where('can_login', true)
            ->where('status', 'active')
            ->whereNotNull('password')
            ->where('phone', 'like', '%'.$suffix)
            ->get()
            ->filter(fn (User $user) => $this->normalizeKenyanPhone($user->phone ?? '') === $normalizedPhone)
            ->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function challengeKey(string $channel, string $identity): string
    {
        return 'password-reset-otp:'.hash('sha256', $channel.'|'.$identity);
    }
}
