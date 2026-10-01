<?php

use App\Models\User;
use App\Notifications\PasswordResetOtpNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('password reset screen can request an email one-time code', function () {
    Notification::fake();
    $user = User::factory()->create(['can_login' => true, 'status' => 'active']);

    $response = $this->get('/forgot-password');
    $response->assertOk();

    $this->post('/forgot-password', ['channel' => 'email', 'identity' => $user->email])
        ->assertRedirect(route('password.reset'));

    Notification::assertSentTo($user, PasswordResetOtpNotification::class);
    $this->get(route('password.reset'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('auth/reset-password')
        ->where('channel', 'email')
        ->where('identity', $user->email));
});

test('a valid email one-time code resets the password and cannot be reused', function () {
    Notification::fake();
    $user = User::factory()->create(['can_login' => true, 'status' => 'active']);
    $otp = null;

    $this->post('/forgot-password', ['channel' => 'email', 'identity' => $user->email]);
    Notification::assertSentTo($user, PasswordResetOtpNotification::class, function ($notification) use (&$otp) {
        $otp = $notification->otp;

        return true;
    });

    $payload = [
        'otp' => $otp,
        'password' => 'different password',
        'password_confirmation' => 'different password',
    ];
    $this->post('/reset-password', $payload)->assertRedirect(route('login'));
    expect(Hash::check('different password', $user->fresh()->password))->toBeTrue();

    $this->withSession(['password_reset' => ['channel' => 'email', 'identity' => $user->email]])
        ->from(route('password.reset'))
        ->post('/reset-password', $payload)
        ->assertSessionHasErrors('otp');
});

test('password reset sends SMS OTP to a normalized Kenyan phone number', function () {
    Http::fake(['https://api.africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => []]], 201)]);
    config([
        'services.africas_talking.username' => 'test-user',
        'services.africas_talking.api_key' => 'test-key',
    ]);
    $user = User::factory()->create([
        'can_login' => true,
        'status' => 'active',
        'phone' => '0711111111',
    ]);

    $this->post('/forgot-password', ['channel' => 'sms', 'identity' => '+254 711 111 111'])
        ->assertRedirect(route('password.reset'));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.africastalking.com/version1/messaging'
        && $request['to'] === '+254711111111'
        && str_contains($request['message'], 'password reset code'));
});

test('unknown accounts receive the same reset response and no code is stored', function () {
    $this->post('/forgot-password', ['channel' => 'email', 'identity' => 'missing@example.com'])
        ->assertRedirect(route('password.reset'));

    $this->from(route('password.reset'))->post('/reset-password', [
        'otp' => '123456',
        'password' => 'different password',
        'password_confirmation' => 'different password',
    ])->assertSessionHasErrors('otp');
});

test('password reset rejects invalid phone numbers and throttles repeated requests', function () {
    $this->from('/forgot-password')->post('/forgot-password', ['channel' => 'sms', 'identity' => 'not-a-number'])
        ->assertSessionHasErrors('identity');

    $user = User::factory()->create(['can_login' => true, 'status' => 'active']);
    foreach (range(1, 4) as $attempt) {
        $response = $this->from('/forgot-password')->post('/forgot-password', ['channel' => 'email', 'identity' => $user->email]);
    }
    $response->assertSessionHasErrors('identity');
});

test('unconfigured SMS delivery fails consistently without checking account existence', function () {
    config(['services.africas_talking.username' => null, 'services.africas_talking.api_key' => null]);
    $known = User::factory()->create(['can_login' => true, 'status' => 'active', 'phone' => '0711111111']);

    foreach ([$known->phone, '0722222222'] as $phone) {
        $this->from('/forgot-password')
            ->post('/forgot-password', ['channel' => 'sms', 'identity' => $phone])
            ->assertSessionHasErrors('channel');
    }
});

test('password reset challenge is kept as a hashed short-lived cache value', function () {
    Notification::fake();
    $user = User::factory()->create(['can_login' => true, 'status' => 'active']);
    $this->post('/forgot-password', ['channel' => 'email', 'identity' => $user->email]);

    $challenge = Cache::get('password-reset-otp:'.hash('sha256', 'email|'.$user->email));
    expect($challenge)->toHaveKeys(['user_id', 'otp_hash'])
        ->and($challenge['otp_hash'])->not->toBe('');
});
