<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class SmsOtpSender
{
    public function assertConfigured(): void
    {
        if (! config('services.africas_talking.username') || ! config('services.africas_talking.api_key')) {
            throw ValidationException::withMessages([
                'channel' => 'SMS delivery is not configured. Contact your school administrator or choose email.',
            ]);
        }
    }

    public function send(string $phone, string $otp): void
    {
        $this->assertConfigured();
        $username = config('services.africas_talking.username');
        $apiKey = config('services.africas_talking.api_key');

        $message = "Your SchoolMS password reset code is {$otp}. It expires in 10 minutes.";
        $payload = ['username' => $username, 'to' => $phone, 'message' => $message];
        $senderId = config('services.africas_talking.sender_id');
        if ($senderId) {
            $payload['from'] = $senderId;
        }

        Http::asForm()
            ->acceptJson()
            ->withHeaders(['apiKey' => $apiKey])
            ->timeout(10)
            ->post(config('services.africas_talking.endpoint'), $payload)
            ->throw();
    }
}
