<?php

namespace App\Http\Controllers\Api;

use App\Models\CallCenterCall;
use App\Models\CallCenterEvent;
use App\Models\CallCenterNumber;
use App\Services\SautiKit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CallCenterController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'call_center');
        abort_unless($this->user($request)->can('call_center.view'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'direction' => ['sometimes', 'in:inbound,outbound'],
        ]);

        $calls = CallCenterCall::where('school_id', $school->id)
            ->when($data['direction'] ?? null, fn ($query, $direction) => $query->where('direction', $direction))
            ->latest()
            ->paginate($data['per_page'] ?? 25);

        return $this->ok(
            $calls->getCollection()->map(fn (CallCenterCall $call) => $this->callPayload($call)),
            [
                'pagination' => [
                    'current_page' => $calls->currentPage(),
                    'last_page' => $calls->lastPage(),
                    'per_page' => $calls->perPage(),
                    'total' => $calls->total(),
                ],
            ]
        );
    }

    public function store(Request $request, SautiKit $sautiKit): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'call_center');
        abort_unless($this->user($request)->can('call_center.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'to' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key') ?: (string) Str::uuid();
        abort_if(strlen($idempotencyKey) > 64, 422, 'Idempotency-Key must not exceed 64 characters.');

        $existing = CallCenterCall::where('school_id', $school->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $this->ok($this->callPayload($existing), [], 200);
        }

        $clientRequestId = (string) Str::uuid();
        $response = $sautiKit->originateCall(
            config('services.sautikit.from_number'),
            $data['to'],
            $clientRequestId,
            $idempotencyKey
        );

        $clientRequestId = $response['client_request_id']
            ?? $response['clientRequestId']
            ?? $clientRequestId;

        $call = DB::transaction(function () use ($school, $request, $data, $idempotencyKey, $clientRequestId, $response) {
            $call = CallCenterCall::create([
                'school_id' => $school->id,
                'initiated_by' => $this->user($request)->id,
                'idempotency_key' => $idempotencyKey,
                'client_request_id' => $clientRequestId,
                'provider_call_id' => $response['call_id'] ?? null,
                'session_id' => $response['session_id'] ?? null,
                'direction' => 'outbound',
                'from_number' => config('services.sautikit.from_number'),
                'to_number' => $data['to'],
                'status' => $response['status'] ?? 'initiated',
                'provider_response' => $response,
            ]);

            $this->associatePendingEvents($call);

            return $call->fresh();
        });

        return $this->ok($this->callPayload($call), [], 201);
    }

    public function inboundRouting(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'call_center');
        abort_unless($this->user($request)->can('call_center.view'), 403, 'You do not have access to this resource.');

        $number = CallCenterNumber::where('school_id', $school->id)->first();

        return $this->ok($number ? [
            'number_id' => $number->provider_number_id,
            'agent_id' => $number->agent_id,
            'mode' => $number->agent_mode,
        ] : null);
    }

    public function configureInboundRouting(Request $request, SautiKit $sautiKit): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'call_center');
        $this->ensureRole($request, 'school_admin');

        $data = $request->validate([
            'number_id' => ['required', 'string', 'max:100'],
            'agent_id' => ['required', 'uuid'],
        ]);

        $number = CallCenterNumber::where('provider_number_id', $data['number_id'])->first();
        abort_if(
            $number && $number->school_id !== $school->id,
            409,
            'This SautiKit number is already assigned to another school.'
        );

        $sautiKit->configureInboundAgent(
            $data['number_id'],
            $data['agent_id'],
            route('api.sautikit.webhook')
        );

        $number = CallCenterNumber::updateOrCreate(
            ['provider_number_id' => $data['number_id']],
            [
                'school_id' => $school->id,
                'agent_id' => $data['agent_id'],
                'agent_mode' => 'always',
            ]
        );

        return $this->ok([
            'number_id' => $number->provider_number_id,
            'agent_id' => $number->agent_id,
            'mode' => $number->agent_mode,
        ]);
    }

    public function webhook(Request $request, SautiKit $sautiKit): JsonResponse
    {
        $secret = config('services.sautikit.webhook_secret');
        abort_unless($secret, 503, 'SautiKit webhook verification is not configured.');
        abort_unless(
            $sautiKit->verifyWebhook($request->getContent(), (string) $request->header('X-Sautikit-Signature'), $secret),
            401,
            'Invalid SautiKit webhook signature.'
        );

        $payload = $request->json()->all();
        abort_unless($payload !== [], 422, 'SautiKit webhook body must be a JSON object.');
        $kind = $payload['kind'] ?? $request->header('X-Sautikit-Event');

        if (! is_string($kind) || ! str_starts_with($kind, 'call.')) {
            return response()->json(['ok' => true]);
        }

        $eventId = $payload['event_id'] ?? $request->header('X-Sautikit-Idempotency-Key');
        abort_unless(
            is_string($eventId) && $eventId !== '' && strlen($eventId) <= 255,
            422,
            'SautiKit call events must include a valid event ID.'
        );

        $eventData = $payload['data'] ?? [];
        abort_unless(is_array($eventData), 422, 'SautiKit call event data is invalid.');
        Validator::make($payload, ['occurred_at' => ['nullable', 'date']])->validate();

        DB::transaction(function () use ($eventId, $kind, $payload, $eventData) {
            $inserted = DB::table('call_center_events')->insertOrIgnore([
                'provider_event_id' => $eventId,
                'call_center_call_id' => null,
                'client_request_id' => $eventData['clientRequestId'] ?? $eventData['client_request_id'] ?? null,
                'event_kind' => $kind,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'occurred_at' => $payload['occurred_at'] ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! $inserted) {
                return;
            }

            $call = $this->findCallForEvent($eventData);
            if (! $call && ($eventData['direction'] ?? null) === 'inbound') {
                $call = $this->createInboundCall($eventData);
            }

            if ($call) {
                $event = CallCenterEvent::where('provider_event_id', $eventId)->firstOrFail();
                $event->call()->associate($call);
                $event->save();
                $this->applyEvent($call, $kind, $payload);
            }
        });

        return response()->json(['ok' => true]);
    }

    private function associatePendingEvents(CallCenterCall $call): void
    {
        $events = CallCenterEvent::whereNull('call_center_call_id')
            ->where(function ($query) use ($call) {
                $query->where('client_request_id', $call->client_request_id);

                if ($call->provider_call_id) {
                    $query->orWhere('payload->data->call_id', $call->provider_call_id);
                }

                if ($call->session_id) {
                    $query->orWhere('payload->data->session_id', $call->session_id);
                    $query->orWhere('payload->data->pbx_call_id', $call->session_id);
                }
            })
            ->orderBy('occurred_at')
            ->get();

        foreach ($events as $event) {
            $event->call()->associate($call);
            $event->save();
            $payload = $event->payload;
            $this->applyEvent($call, $event->event_kind, $payload);
        }
    }

    private function findCallForEvent(array $eventData): ?CallCenterCall
    {
        $clientRequestId = $eventData['clientRequestId'] ?? $eventData['client_request_id'] ?? null;
        $providerCallId = $eventData['call_id'] ?? null;
        $sessionId = $eventData['session_id'] ?? $eventData['pbx_call_id'] ?? null;

        if (! $clientRequestId && ! $providerCallId && ! $sessionId) {
            return null;
        }

        return CallCenterCall::query()
            ->when($clientRequestId, fn ($query) => $query->orWhere('client_request_id', $clientRequestId))
            ->when($providerCallId, fn ($query) => $query->orWhere('provider_call_id', $providerCallId))
            ->when($sessionId, fn ($query) => $query->orWhere('session_id', $sessionId))
            ->first();
    }

    private function createInboundCall(array $eventData): ?CallCenterCall
    {
        $numberId = $eventData['number_id'] ?? null;
        $providerCallId = $eventData['call_id'] ?? null;
        $sessionId = $eventData['session_id'] ?? $eventData['pbx_call_id'] ?? null;
        $from = $eventData['from'] ?? null;
        $to = $eventData['to'] ?? null;

        if (! is_string($numberId) || ! is_string($from) || ! is_string($to)
            || (! is_string($providerCallId) && ! is_string($sessionId))) {
            return null;
        }

        $number = CallCenterNumber::where('provider_number_id', $numberId)->first();
        if (! $number) {
            return null;
        }

        $lookup = CallCenterCall::where('school_id', $number->school_id)
            ->where('direction', 'inbound');

        if ($providerCallId) {
            $lookup->where('provider_call_id', $providerCallId);
        } else {
            $lookup->where('session_id', $sessionId);
        }

        $existing = $lookup->first();
        if ($existing) {
            return $existing;
        }

        return CallCenterCall::create([
            'school_id' => $number->school_id,
            'direction' => 'inbound',
            'idempotency_key' => 'inbound:'.substr(hash('sha256', $providerCallId ?? $sessionId), 0, 56),
            'client_request_id' => (string) Str::uuid(),
            'provider_call_id' => $providerCallId,
            'session_id' => $sessionId,
            'provider_number_id' => $numberId,
            'from_number' => $from,
            'to_number' => $to,
            'status' => 'initiated',
        ]);
    }

    private function applyEvent(CallCenterCall $call, string $kind, array $payload): void
    {
        $status = match ($kind) {
            'call.started' => 'started',
            'call.answered' => 'answered',
            'call.completed' => 'completed',
            'call.failed' => 'failed',
            default => null,
        };

        if (! $status) {
            return;
        }

        if (in_array($call->status, ['completed', 'failed'], true)) {
            return;
        }

        $progress = ['initiated' => 0, 'ringing' => 1, 'started' => 1, 'answered' => 2, 'completed' => 3, 'failed' => 3];
        if (($progress[$status] ?? 0) < ($progress[$call->status] ?? 0)) {
            return;
        }

        $occurredAt = $payload['occurred_at'] ?? now();
        $updates = ['status' => $status];

        if ($status === 'started') {
            $updates['started_at'] = $occurredAt;
        } elseif ($status === 'answered') {
            $updates['answered_at'] = $occurredAt;
        } else {
            $updates['completed_at'] = $occurredAt;
        }

        $call->update($updates);
    }

    private function callPayload(CallCenterCall $call): array
    {
        return [
            'id' => $call->id,
            'direction' => $call->direction,
            'provider_call_id' => $call->provider_call_id,
            'session_id' => $call->session_id,
            'number_id' => $call->provider_number_id,
            'from' => $call->from_number,
            'to' => $call->to_number,
            'status' => $call->status,
            'started_at' => $call->started_at?->toIso8601String(),
            'answered_at' => $call->answered_at?->toIso8601String(),
            'completed_at' => $call->completed_at?->toIso8601String(),
            'created_at' => $call->created_at?->toIso8601String(),
        ];
    }
}
