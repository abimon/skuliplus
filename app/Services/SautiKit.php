<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SautiKit
{
    public function originateCall(?string $from, string $to, string $clientRequestId, string $idempotencyKey): array
    {
        $apiKey = config('services.sautikit.api_key');

        if (! $apiKey || ! $from) {
            throw ValidationException::withMessages([
                'call' => 'SautiKit is not configured. Contact your system administrator.',
            ]);
        }

        $response = Http::baseUrl(rtrim(config('services.sautikit.base_url'), '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout(15)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/v1/calls', [
                'from' => $from,
                'to' => [$to],
                'client_request_id' => $clientRequestId,
            ])
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new \UnexpectedValueException('SautiKit returned an invalid call response.');
        }

        return $response;
    }

    public function configureInboundAgent(string $numberId, string $agentId, string $eventsUrl): array
    {
        $apiKey = config('services.sautikit.api_key');

        if (! $apiKey) {
            throw ValidationException::withMessages([
                'call' => 'SautiKit is not configured. Contact your system administrator.',
            ]);
        }

        $handoverVoicemail = ['greeting_text' => 'Please leave a message after the tone.'];
        $response = Http::baseUrl(rtrim(config('services.sautikit.base_url'), '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout(15)
            ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->put('/v1/numbers/'.rawurlencode($numberId).'/routing', [
                'events_url' => $eventsUrl,
                'inbound_agent' => [
                    'enabled' => true,
                    'agent_id' => $agentId,
                    'mode' => 'always',
                    'handover_voicemail' => $handoverVoicemail,
                ],
            ])
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new \UnexpectedValueException('SautiKit returned an invalid inbound routing response.');
        }

        return $response;
    }

    public function mintWebrtcToken(string $clientName): array
    {
        $response = $this->request('post', '/v1/webrtc/token', [
            'client_name' => $clientName,
            'role' => 'dialer',
        ]);

        if (! isset($response['token'], $response['endpoint'])
            || ! is_string($response['token']) || trim($response['token']) === ''
            || ! is_string($response['endpoint']) || trim($response['endpoint']) === '') {
            throw new \UnexpectedValueException('SautiKit returned an incomplete WebRTC token response.');
        }

        return $response;
    }

    public function startSupervision(
        string $callId,
        string $clientIdentity,
        string $label,
        string $mode,
        string $announce,
        bool $notifyAgent
    ): array {
        $response = $this->request('post', '/v1/calls/'.rawurlencode($callId).'/supervision', [
            'mode' => $mode,
            'announce' => $announce,
            'label' => mb_substr($label, 0, 64),
            'notify_agent' => $notifyAgent,
            'supervisor' => ['type' => 'client', 'identity' => $clientIdentity],
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $supervisionId = $response['supervision_id'] ?? $response['supervisionId'] ?? null;
        $supervisorLegId = $response['supervisor_leg_id'] ?? $response['supervisorLegId'] ?? null;
        $returnedMode = $response['mode'] ?? $mode;
        if (! is_string($supervisionId) || ! is_string($supervisorLegId)
            || ! in_array($returnedMode, ['listen', 'whisper', 'barge'], true)) {
            throw new \UnexpectedValueException('SautiKit returned an incomplete supervision response.');
        }

        return [
            'supervisionId' => $supervisionId,
            'supervisorLegId' => $supervisorLegId,
            'mode' => $returnedMode,
        ];
    }

    public function setSupervisionMode(string $callId, string $mode): array
    {
        return $this->request('patch', '/v1/calls/'.rawurlencode($callId).'/supervision', ['mode' => $mode]);
    }

    public function stopSupervision(string $callId): void
    {
        $this->request('delete', '/v1/calls/'.rawurlencode($callId).'/supervision');
    }

    public function hangupCall(string $callId): array
    {
        return $this->request('post', '/v1/calls/'.rawurlencode($callId).'/hangup');
    }

    private function request(string $method, string $path, array $payload = [], array $headers = []): array
    {
        $apiKey = config('services.sautikit.api_key');
        if (! $apiKey) {
            throw ValidationException::withMessages([
                'call' => 'SautiKit is not configured. Contact your system administrator.',
            ]);
        }

        $request = Http::baseUrl(rtrim(config('services.sautikit.base_url'), '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout(15)
            ->withHeaders($headers);
        $response = $request->{$method}($path, $payload);

        if (! $response->successful()) {
            $message = $response->json('error.message')
                ?? $response->json('message')
                ?? 'SautiKit could not process the call request.';

            if ($response->status() < 500) {
                throw ValidationException::withMessages(['call' => $message]);
            }

            throw new \RuntimeException("SautiKit request failed with HTTP {$response->status()}: {$message}");
        }

        if ($response->status() === 204 || $response->body() === '') {
            return [];
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new \UnexpectedValueException('SautiKit returned an invalid API response.');
        }

        return $data;
    }

    public function verifyWebhook(string $body, string $signature, string $secret): bool
    {
        if (preg_match('/(?:^|,)t=([0-9]+)(?:,|$)/', $signature, $timestampMatch)
            && preg_match('/(?:^|,)v1=([a-fA-F0-9]{64})(?:,|$)/', $signature, $digestMatch)) {
            $timestamp = $timestampMatch[1];

            if (abs(time() - (int) $timestamp) > 300) {
                return false;
            }

            $expected = hash_hmac('sha256', $body.'.'.$timestamp, $secret);

            return hash_equals($expected, strtolower($digestMatch[1]));
        }

        if (preg_match('/^[a-fA-F0-9]{64}$/', $signature) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $secret), strtolower($signature));
    }
}
