<?php

namespace App\Http\Controllers;

use App\Models\CallCenterCall;
use App\Models\CallCenterNumber;
use App\Services\SautiKit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CallCenterController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can('call_center.view'), 403);
        $schoolId = $user->school_id;
        abort_unless($schoolId, 403);
        $canManage = $user->can('call_center.manage');

        $data = $request->validate([
            'direction' => ['sometimes', 'in:inbound,outbound'],
        ]);

        $calls = CallCenterCall::where('school_id', $schoolId)
            ->when($data['direction'] ?? null, fn ($query, $direction) => $query->where('direction', $direction))
            ->with('initiator:id,name')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (CallCenterCall $call) => [
                'id' => $call->id,
                'direction' => $call->direction,
                'from' => $call->from_number,
                'to' => $call->to_number,
                'status' => $call->status,
                'provider_call_id' => $canManage ? $call->provider_call_id : null,
                'session_id' => $canManage ? $call->session_id : null,
                'provider_number_id' => $canManage ? $call->provider_number_id : null,
                'started_at' => $call->started_at?->toIso8601String(),
                'created_at' => $call->created_at?->toIso8601String(),
                'agent' => $call->initiator?->name,
            ]);

        $number = CallCenterNumber::where('school_id', $schoolId)->first();

        return Inertia::render('call-center/index', [
            'school' => $user->school()->first(['name', 'code', 'county', 'logo_path']),
            'calls' => $calls,
            'direction' => $data['direction'] ?? 'all',
            'canManage' => $canManage,
            'canConfigureInbound' => $user->hasRole('school_admin'),
            'inboundRouting' => $number ? [
                'number_id' => $number->provider_number_id,
                'agent_id' => $number->agent_id,
            ] : null,
            'stats' => [
                'total' => CallCenterCall::where('school_id', $schoolId)->count(),
                'inbound' => CallCenterCall::where('school_id', $schoolId)->where('direction', 'inbound')->count(),
                'outbound' => CallCenterCall::where('school_id', $schoolId)->where('direction', 'outbound')->count(),
                'active' => CallCenterCall::where('school_id', $schoolId)->whereNotIn('status', ['completed', 'failed'])->count(),
            ],
        ]);
    }

    public function store(Request $request, SautiKit $sautiKit): RedirectResponse
    {
        abort_unless($request->user()->can('call_center.manage'), 403);
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'to' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ]);

        $from = config('services.sautikit.from_number');
        $clientRequestId = (string) Str::uuid();
        $idempotencyKey = (string) Str::uuid();
        $response = $sautiKit->originateCall($from, $data['to'], $clientRequestId, $idempotencyKey);

        $call = CallCenterCall::create([
            'school_id' => $schoolId,
            'initiated_by' => $request->user()->id,
            'idempotency_key' => $idempotencyKey,
            'client_request_id' => $response['client_request_id'] ?? $response['clientRequestId'] ?? $clientRequestId,
            'provider_call_id' => $response['call_id'] ?? null,
            'session_id' => $response['session_id'] ?? null,
            'direction' => 'outbound',
            'from_number' => $from,
            'to_number' => $data['to'],
            'status' => $response['status'] ?? 'initiated',
            'provider_response' => $response,
        ]);

        $this->associatePendingEvents($call);

        return back()->with('success', 'Call placed. Its status will update as SautiKit reports call events.');
    }

    public function configureInboundRouting(Request $request, SautiKit $sautiKit): RedirectResponse
    {
        abort_unless($request->user()->hasRole('school_admin'), 403);
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'number_id' => ['required', 'string', 'max:100'],
            'agent_id' => ['required', 'uuid'],
        ]);

        $number = CallCenterNumber::where('provider_number_id', $data['number_id'])->first();
        abort_if($number && $number->school_id !== $schoolId, 409, 'This SautiKit number is already assigned to another school.');

        $sautiKit->configureInboundAgent($data['number_id'], $data['agent_id'], route('api.sautikit.webhook'));

        CallCenterNumber::updateOrCreate(
            ['provider_number_id' => $data['number_id']],
            ['school_id' => $schoolId, 'agent_id' => $data['agent_id'], 'agent_mode' => 'always']
        );

        return back()->with('success', 'Inbound calls are now routed to the selected SautiKit AI agent.');
    }

    public function webrtcToken(Request $request, SautiKit $sautiKit): JsonResponse
    {
        abort_unless($request->user()->can('call_center.manage'), 403);
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $clientName = "skuliplus-school-{$schoolId}-user-{$request->user()->id}";
        $token = $sautiKit->mintWebrtcToken($clientName);

        return response()->json([
            'token' => $token['token'],
            'endpoint' => $token['endpoint'],
            'protocol' => $token['protocol'] ?? 'hd-protocol',
            'turnServer' => $token['turnServer'] ?? $token['turn_server'] ?? null,
            'clientName' => $token['clientName'] ?? $token['client_name'] ?? $clientName,
            'expiresIn' => $token['expiresIn'] ?? $token['expires_in'] ?? null,
        ]);
    }

    public function recordActiveCall(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('call_center.manage'), 403);
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $data = $request->validate([
            'to' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'session_id' => ['required', 'string', 'max:255'],
        ]);
        $existing = CallCenterCall::where('school_id', $schoolId)
            ->where('session_id', $data['session_id'])
            ->first();
        if ($existing) {
            return response()->json(['data' => ['id' => $existing->id]], 200);
        }

        $call = CallCenterCall::create([
            'school_id' => $schoolId,
            'initiated_by' => $request->user()->id,
            'idempotency_key' => 'webrtc:'.substr(hash('sha256', $data['session_id']), 0, 56),
            'client_request_id' => (string) Str::uuid(),
            'session_id' => $data['session_id'],
            'direction' => 'outbound',
            'from_number' => config('services.sautikit.from_number') ?? 'WebRTC',
            'to_number' => $data['to'],
            'status' => 'initiated',
        ]);

        $this->associatePendingEvents($call);

        return response()->json(['data' => ['id' => $call->id]], 201);
    }

    public function hangupCall(Request $request, string $callId, SautiKit $sautiKit): JsonResponse
    {
        abort_unless($request->user()->can('call_center.manage'), 403);
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        $call = CallCenterCall::where('school_id', $schoolId)
            ->where(function ($query) use ($callId) {
                $query->where('id', $callId)
                    ->orWhere('provider_call_id', $callId)
                    ->orWhere('session_id', $callId);
            })
            ->firstOrFail();
        abort_if(in_array($call->status, ['completed', 'failed'], true), 409, 'This call has already ended.');

        $providerId = $call->provider_call_id ?: $call->session_id;
        abort_unless($providerId, 409, 'This call cannot be controlled because it has no provider ID.');
        $sautiKit->hangupCall($providerId);

        return response()->json(['requested' => true]);
    }

    public function startSupervision(Request $request, string $providerCallId, SautiKit $sautiKit): JsonResponse
    {
        abort_unless($request->user()->can('call_center.manage'), 403);
        $call = $this->findSchoolCall($request, $providerCallId);
        $this->ensureSupervisableCall($call);

        $data = $request->validate([
            'mode' => ['sometimes', 'in:listen,whisper,barge'],
            'announce' => ['sometimes', 'in:none,agent,both'],
            'label' => ['sometimes', 'string', 'max:64'],
            'notifyAgent' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $clientName = "skuliplus-school-{$user->school_id}-user-{$user->id}";

        return response()->json($sautiKit->startSupervision(
            $providerCallId,
            $clientName,
            $data['label'] ?? $user->name,
            $data['mode'] ?? 'listen',
            $data['announce'] ?? 'none',
            $data['notifyAgent'] ?? true,
        ));
    }

    public function setSupervisionMode(Request $request, string $providerCallId, SautiKit $sautiKit): JsonResponse
    {
        abort_unless($request->user()->can('call_center.manage'), 403);
        $call = $this->findSchoolCall($request, $providerCallId);
        $this->ensureSupervisableCall($call);

        $data = $request->validate(['mode' => ['required', 'in:listen,whisper,barge']]);
        $sautiKit->setSupervisionMode($providerCallId, $data['mode']);

        return response()->json(['mode' => $data['mode']]);
    }

    public function stopSupervision(Request $request, string $providerCallId, SautiKit $sautiKit): JsonResponse
    {
        abort_unless($request->user()->can('call_center.manage'), 403);
        $this->findSchoolCall($request, $providerCallId);
        $sautiKit->stopSupervision($providerCallId);

        return response()->json(['stopped' => true]);
    }

    private function findSchoolCall(Request $request, string $providerCallId): CallCenterCall
    {
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        return CallCenterCall::where('school_id', $schoolId)
            ->where(function ($query) use ($providerCallId) {
                $query->where('provider_call_id', $providerCallId)
                    ->orWhere('session_id', $providerCallId);
            })
            ->firstOrFail();
    }

    private function ensureSupervisableCall(CallCenterCall $call): void
    {
        abort_unless($call->status === 'answered', 409, 'Only an answered call can be supervised.');
    }

    private function associatePendingEvents(CallCenterCall $call): void
    {
        $events = $call->newQuery()->getConnection()->table('call_center_events')
            ->whereNull('call_center_call_id')
            ->where(function ($query) use ($call) {
                $query->where('client_request_id', $call->client_request_id);
                if ($call->provider_call_id) {
                    $query->orWhere('payload->data->call_id', $call->provider_call_id);
                }
                if ($call->session_id) {
                    $query->orWhere('payload->data->session_id', $call->session_id)
                        ->orWhere('payload->data->pbx_call_id', $call->session_id);
                }
            })
            ->orderBy('occurred_at')
            ->get();

        foreach ($events as $event) {
            $payload = json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR);
            $call->events()->where('provider_event_id', $event->provider_event_id)
                ->update(['call_center_call_id' => $call->id]);

            $status = match ($event->event_kind) {
                'call.started' => 'started',
                'call.answered' => 'answered',
                'call.completed' => 'completed',
                'call.failed' => 'failed',
                default => null,
            };

            if ($status) {
                $call->update([
                    'status' => $status,
                    match ($status) {
                        'started' => 'started_at',
                        'answered' => 'answered_at',
                        default => 'completed_at',
                    } => $payload['occurred_at'] ?? now(),
                ]);
            }
        }
    }
}
