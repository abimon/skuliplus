<?php

use App\Models\CallCenterCall;
use App\Models\CallCenterNumber;
use App\Models\School;
use App\Models\SchoolModule;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolePermissionSeeder::class);

    SchoolModule::updateOrCreate(
        ['key' => 'call_center'],
        ['name' => 'Call centre', 'description' => 'Inbound and outbound calls', 'is_active' => true]
    );

    $this->school = School::create([
        'code' => 'CALLWEB',
        'name' => 'Call Centre Web School',
        'enabled_modules' => ['users', 'call_center'],
    ]);

    $this->admin = User::factory()->create([
        'school_id' => $this->school->id,
        'can_login' => true,
        'status' => 'active',
    ]);
    $this->admin->assignRole('school_admin');

    config([
        'services.sautikit.api_key' => 'test-api-key',
        'services.sautikit.from_number' => '+254200000001',
    ]);
});

test('school navigation and dashboard show the call centre module when enabled', function () {
    $this->actingAs($this->admin)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('navigation', fn ($navigation) => collect($navigation)->contains(fn ($item) => $item['key'] === 'call_center' && $item['name'] === 'Call centre' && $item['href'] === '/call-center'))
            ->where('modules', fn ($modules) => collect($modules)->contains(fn ($module) => $module['key'] === 'call_center' && $module['href'] === '/call-center'))
        );
});

test('the call centre page shows school calls and routing state', function () {
    CallCenterNumber::create([
        'school_id' => $this->school->id,
        'provider_number_id' => 'sautikit-number-web',
        'agent_id' => '9d2b1f53-8c0e-4f1d-9a6b-5d3a8c47e9f0',
    ]);
    CallCenterCall::create([
        'school_id' => $this->school->id,
        'initiated_by' => $this->admin->id,
        'idempotency_key' => 'web-inbound-1',
        'client_request_id' => '9d2b1f53-8c0e-4f1d-9a6b-5d3a8c47e0',
        'provider_call_id' => 'web-call-1',
        'direction' => 'inbound',
        'provider_number_id' => 'sautikit-number-web',
        'from_number' => '+254700000001',
        'to_number' => '+254200000001',
        'status' => 'completed',
    ]);

    $this->actingAs($this->admin)->get('/call-center?direction=inbound')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('call-center/index')
            ->where('school.name', 'Call Centre Web School')
            ->where('direction', 'inbound')
            ->where('stats.inbound', 1)
            ->where('calls.0.direction', 'inbound')
            ->where('inboundRouting.number_id', 'sautikit-number-web')
            ->where('canConfigureInbound', true)
        );
});

test('an authorized operator can place an outbound call from the call centre', function () {
    Http::fake([
        'https://api.sautikit.com/v1/calls' => Http::response([
            'call_id' => 'web-outbound-call',
            'status' => 'ringing',
        ], 201),
    ]);

    $this->actingAs($this->admin)->post('/call-center/calls', ['to' => '+254712345678'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(CallCenterCall::where('school_id', $this->school->id)->firstOrFail())
        ->direction->toBe('outbound')
        ->status->toBe('ringing');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.sautikit.com/v1/calls'
        && $request['from'] === '+254200000001'
        && $request['to'] === ['+254712345678']);
});

test('an authorized operator receives a short-lived browser WebRTC token without exposing the API key', function () {
    Http::fake([
        'https://api.sautikit.com/v1/webrtc/token' => Http::response([
            'token' => 'short-lived-webrtc-token',
            'endpoint' => 'wss://webrtc.example.test',
            'protocol' => 'hd-protocol',
            'turnServer' => ['urls' => 'turn:turn.example.test'],
            'clientName' => 'browser-supervisor',
            'expiresIn' => 300,
        ]),
    ]);

    $this->actingAs($this->admin)
        ->getJson('/call-center/webrtc-token')
        ->assertOk()
        ->assertJsonPath('token', 'short-lived-webrtc-token')
        ->assertJsonPath('clientName', 'browser-supervisor')
        ->assertJsonMissingPath('api_key');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.sautikit.com/v1/webrtc/token'
        && $request['role'] === 'dialer'
        && $request['client_name'] === "skuliplus-school-{$this->school->id}-user-{$this->admin->id}"
        && $request->hasHeader('Authorization', 'Bearer test-api-key'));
});

test('an authorized operator can record a browser-originated WebRTC call', function () {
    $payload = ['to' => '+254712345678', 'session_id' => 'HD_web_session_1'];

    $this->actingAs($this->admin)
        ->postJson('/call-center/calls/active', $payload)
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id']]);
    $this->actingAs($this->admin)
        ->postJson('/call-center/calls/active', $payload)
        ->assertOk();

    expect(CallCenterCall::where('school_id', $this->school->id)->count())->toBe(1);
});

test('an authorized operator can end a recorded call for everyone', function () {
    $call = CallCenterCall::create([
        'school_id' => $this->school->id,
        'initiated_by' => $this->admin->id,
        'idempotency_key' => 'web-hangup',
        'client_request_id' => (string) Str::uuid(),
        'session_id' => 'HD_web_hangup',
        'direction' => 'inbound',
        'from_number' => '+254712345678',
        'to_number' => '+254200000001',
        'status' => 'answered',
    ]);
    Http::fake([
        'https://api.sautikit.com/v1/calls/HD_web_hangup/hangup' => Http::response([
            'call_id' => 'provider-hangup-call',
            'status' => 'hung_up',
        ]),
    ]);

    $this->actingAs($this->admin)
        ->postJson("/call-center/calls/{$call->id}/hangup")
        ->assertOk()
        ->assertJsonPath('requested', true);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/calls/HD_web_hangup/hangup'));
});

test('an authorized operator can start, change, and stop supervision for a school call', function () {
    CallCenterCall::create([
        'school_id' => $this->school->id,
        'initiated_by' => $this->admin->id,
        'idempotency_key' => 'supervise-call',
        'client_request_id' => 'supervise-client-request',
        'provider_call_id' => 'provider-call-1',
        'session_id' => 'supervise-session-1',
        'direction' => 'outbound',
        'from_number' => '+254200000001',
        'to_number' => '+254712345678',
        'status' => 'answered',
    ]);
    Http::fake(function ($request) {
        if ($request->method() === 'POST') {
            return Http::response([
                'supervision_id' => 'supervision-1',
                'supervisor_leg_id' => 'supervisor-leg-1',
                'mode' => 'listen',
            ]);
        }

        return Http::response([], $request->method() === 'DELETE' ? 204 : 200);
    });

    $this->actingAs($this->admin)
        ->postJson('/call-center/calls/supervise-session-1/supervision', [
            'mode' => 'listen',
            'announce' => 'none',
            'label' => 'Call centre operator',
        ])
        ->assertOk()
        ->assertJsonPath('supervisionId', 'supervision-1')
        ->assertJsonPath('supervisorLegId', 'supervisor-leg-1');

    $this->patchJson('/call-center/calls/supervise-session-1/supervision', ['mode' => 'barge'])
        ->assertOk()
        ->assertJsonPath('mode', 'barge');

    $this->deleteJson('/call-center/calls/supervise-session-1/supervision')
        ->assertOk()
        ->assertJsonPath('stopped', true);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/v1/calls/supervise-session-1/supervision')
        && $request['supervisor']['identity'] === "skuliplus-school-{$this->school->id}-user-{$this->admin->id}");
    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && $request['mode'] === 'barge');
    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/v1/calls/supervise-session-1/supervision'));
});

test('an operator cannot supervise a call that is not in the answered state', function () {
    CallCenterCall::create([
        'school_id' => $this->school->id,
        'initiated_by' => $this->admin->id,
        'idempotency_key' => 'ringing-call',
        'client_request_id' => 'ringing-client-request',
        'provider_call_id' => 'ringing-call-1',
        'direction' => 'outbound',
        'from_number' => '+254200000001',
        'to_number' => '+254712345678',
        'status' => 'ringing',
    ]);
    Http::fake();

    $this->actingAs($this->admin)
        ->postJson('/call-center/calls/ringing-call-1/supervision')
        ->assertStatus(409);

    Http::assertNothingSent();
});

test('a school administrator can configure inbound AI call routing from the page', function () {
    Http::fake([
        'https://api.sautikit.com/v1/numbers/sautikit-number-web/routing' => Http::response(['routing' => []]),
    ]);

    $agentId = '9d2b1f53-8c0e-4f1d-9a6b-5d3a8c47e9f0';
    $this->actingAs($this->admin)->put('/call-center/inbound-routing', [
        'number_id' => 'sautikit-number-web',
        'agent_id' => $agentId,
    ])->assertRedirect()->assertSessionHas('success');

    expect(CallCenterNumber::where('school_id', $this->school->id)->firstOrFail()->agent_id)->toBe($agentId);
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request['inbound_agent']['agent_id'] === $agentId);
});

test('call centre page is unavailable when the school does not have the module', function () {
    $this->school->update(['enabled_modules' => ['users']]);

    $this->actingAs($this->admin)->get('/call-center')
        ->assertRedirect(route('module.unavailable'));
});
