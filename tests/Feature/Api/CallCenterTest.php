<?php

use App\Models\CallCenterCall;
use App\Models\CallCenterEvent;
use App\Models\CallCenterNumber;
use App\Models\School;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->school = School::create([
        'code' => 'CALLTEST',
        'name' => 'Call Center Test School',
        'enabled_modules' => ['call_center'],
    ]);

    $this->agent = User::factory()->create([
        'school_id' => $this->school->id,
        'can_login' => true,
        'status' => 'active',
    ]);
    $this->agent->assignRole('support_staff');

    config([
        'services.sautikit.api_key' => 'test-api-key',
        'services.sautikit.from_number' => '+254200000001',
        'services.sautikit.webhook_secret' => 'test-webhook-secret',
    ]);

    Sanctum::actingAs($this->agent, ['mobile']);
});

test('a call center agent can place a call and retrieve its school call history', function () {
    Http::fake([
        'https://api.sautikit.com/v1/calls' => Http::response([
            'call_id' => 'sautikit-call-123',
            'session_id' => 'HD_123',
            'status' => 'ringing',
        ], 201),
    ]);

    $this->postJson('/api/call-center/calls', ['to' => '+254712345678'])
        ->assertCreated()
        ->assertJsonPath('data.provider_call_id', 'sautikit-call-123')
        ->assertJsonPath('data.status', 'ringing');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.sautikit.com/v1/calls'
        && $request['from'] === '+254200000001'
        && $request['to'] === ['+254712345678']
        && $request->hasHeader('Authorization', 'Bearer test-api-key')
        && $request->hasHeader('Idempotency-Key'));

    $this->getJson('/api/call-center/calls')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.to', '+254712345678');
});

test('an idempotency key does not place a duplicate call', function () {
    Http::fake([
        'https://api.sautikit.com/v1/calls' => Http::response([
            'call_id' => 'sautikit-call-idempotent',
            'status' => 'ringing',
        ], 201),
    ]);

    $this->postJson('/api/call-center/calls', ['to' => '+254712345678'], [
        'Idempotency-Key' => 'school-follow-up-1',
    ])->assertCreated();

    $this->postJson('/api/call-center/calls', ['to' => '+254712345678'], [
        'Idempotency-Key' => 'school-follow-up-1',
    ])->assertOk();

    Http::assertSentCount(1);
    expect(CallCenterCall::count())->toBe(1);
});

test('SautiKit call events are signed, deduplicated, and update call status', function () {
    Http::fake([
        'https://api.sautikit.com/v1/calls' => Http::response([
            'call_id' => 'sautikit-call-456',
            'status' => 'ringing',
        ], 201),
    ]);

    $this->postJson('/api/call-center/calls', ['to' => '+254712345678'])->assertCreated();
    $call = CallCenterCall::firstOrFail();
    $timestamp = (string) time();
    $body = json_encode([
        'kind' => 'call.answered',
        'event_id' => 'event-123',
        'occurred_at' => now()->toIso8601String(),
        'data' => [
            'call_id' => 'sautikit-call-456',
            'status' => 'answered',
        ],
    ], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $body.'.'.$timestamp, 'test-webhook-secret');

    foreach ([1, 2] as $_) {
        $this->call('POST', '/api/webhooks/sautikit', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SAUTIKIT_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body)->assertOk()->assertJsonPath('ok', true);
    }

    expect($call->fresh()->status)->toBe('answered')
        ->and(CallCenterEvent::count())->toBe(1);
});

test('SautiKit webhook rejects an invalid signature', function () {
    $this->call('POST', '/api/webhooks/sautikit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SAUTIKIT_SIGNATURE' => str_repeat('0', 64),
    ], '{"kind":"call.started","event_id":"event-1","data":{}}')
        ->assertUnauthorized();
});

test('SautiKit call callbacks with raw-body signatures are accepted', function () {
    $body = json_encode([
        'kind' => 'call.started',
        'event_id' => 'event-body-signature',
        'data' => [
            'call_id' => 'unmatched-provider-call',
            'direction' => 'inbound',
            'status' => 'started',
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', '/api/webhooks/sautikit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SAUTIKIT_SIGNATURE' => hash_hmac('sha256', $body, 'test-webhook-secret'),
    ], $body)->assertOk()->assertJsonPath('ok', true);

    expect(CallCenterEvent::count())->toBe(1);
});

test('a callback arriving before the call response is correlated once the call is saved', function () {
    $body = json_encode([
        'kind' => 'call.answered',
        'event_id' => 'event-before-call',
        'data' => [
            'call_id' => 'sautikit-call-arrived-first',
            'status' => 'answered',
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', '/api/webhooks/sautikit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SAUTIKIT_SIGNATURE' => hash_hmac('sha256', $body, 'test-webhook-secret'),
    ], $body)->assertOk();

    Http::fake([
        'https://api.sautikit.com/v1/calls' => Http::response([
            'call_id' => 'sautikit-call-arrived-first',
            'status' => 'ringing',
        ], 201),
    ]);

    $this->postJson('/api/call-center/calls', ['to' => '+254712345678'])->assertCreated();

    expect(CallCenterCall::firstOrFail()->status)->toBe('answered')
        ->and(CallCenterEvent::firstOrFail()->call_center_call_id)->toBe(CallCenterCall::firstOrFail()->id);
});

test('call center access requires the school module to be enabled', function () {
    $this->school->update(['enabled_modules' => ['users']]);

    $this->getJson('/api/call-center/calls')->assertForbidden();
});

test('inbound events create a school-scoped call record and update its status', function () {
    CallCenterNumber::create([
        'school_id' => $this->school->id,
        'provider_number_id' => 'sautikit-number-123',
        'agent_id' => '9d2b1f53-8c0e-4f1d-9a6b-5d3a8c47e9f0',
        'agent_mode' => 'always',
    ]);

    $started = json_encode([
        'kind' => 'call.started',
        'event_id' => 'inbound-started-1',
        'occurred_at' => now()->toIso8601String(),
        'data' => [
            'call_id' => 'inbound-provider-call-1',
            'direction' => 'inbound',
            'from' => '+254700000009',
            'to' => '+254200000001',
            'number_id' => 'sautikit-number-123',
            'status' => 'started',
        ],
    ], JSON_THROW_ON_ERROR);

    postSautiKitEvent($this, $started)->assertOk();

    expect(CallCenterCall::count())->toBe(1)
        ->and(CallCenterCall::firstOrFail()->direction)->toBe('inbound')
        ->and(CallCenterCall::firstOrFail()->from_number)->toBe('+254700000009');

    $completed = json_encode([
        'kind' => 'call.completed',
        'event_id' => 'inbound-completed-1',
        'occurred_at' => now()->toIso8601String(),
        'data' => [
            'call_id' => 'inbound-provider-call-1',
            'direction' => 'inbound',
            'from' => '+254700000009',
            'to' => '+254200000001',
            'number_id' => 'sautikit-number-123',
            'status' => 'completed',
        ],
    ], JSON_THROW_ON_ERROR);

    postSautiKitEvent($this, $completed)->assertOk();

    expect(CallCenterCall::count())->toBe(1)
        ->and(CallCenterCall::firstOrFail()->fresh()->status)->toBe('completed');

    $this->getJson('/api/call-center/calls?direction=inbound')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.direction', 'inbound');
});

test('a school administrator can bind an inbound number to a published SautiKit agent', function () {
    Http::fake([
        'https://api.sautikit.com/v1/numbers/sautikit-number-789/routing' => Http::response([
            'routing' => ['inbound_agent' => ['enabled' => true]],
        ]),
    ]);

    $admin = User::factory()->create(['school_id' => $this->school->id]);
    $admin->assignRole('school_admin');
    Sanctum::actingAs($admin, ['mobile']);

    $agentId = '9d2b1f53-8c0e-4f1d-9a6b-5d3a8c47e9f0';
    $this->putJson('/api/call-center/inbound-routing', [
        'number_id' => 'sautikit-number-789',
        'agent_id' => $agentId,
    ])->assertOk()
        ->assertJsonPath('data.number_id', 'sautikit-number-789')
        ->assertJsonPath('data.agent_id', $agentId)
        ->assertJsonPath('data.mode', 'always');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://api.sautikit.com/v1/numbers/sautikit-number-789/routing'
        && $request['inbound_agent']['agent_id'] === $agentId
        && $request['inbound_agent']['mode'] === 'always'
        && $request['events_url'] === route('api.sautikit.webhook')
        && isset($request['inbound_agent']['handover_voicemail']));

    $this->getJson('/api/call-center/inbound-routing')
        ->assertOk()
        ->assertJsonPath('data.number_id', 'sautikit-number-789')
        ->assertJsonPath('data.agent_id', $agentId);
});

function postSautiKitEvent($testCase, string $body)
{
    return $testCase->call('POST', '/api/webhooks/sautikit', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SAUTIKIT_SIGNATURE' => hash_hmac('sha256', $body, 'test-webhook-secret'),
    ], $body);
}
