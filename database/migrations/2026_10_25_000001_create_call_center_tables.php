<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_center_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 64);
            $table->uuid('client_request_id')->unique();
            $table->string('provider_call_id')->nullable()->unique();
            $table->string('session_id')->nullable()->index();
            $table->string('from_number', 32);
            $table->string('to_number', 32);
            $table->string('status', 32)->default('initiated');
            $table->json('provider_response')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'idempotency_key']);
        });

        Schema::create('call_center_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_center_call_id')->nullable()->constrained('call_center_calls')->nullOnDelete();
            $table->string('provider_event_id')->unique();
            $table->uuid('client_request_id')->nullable()->index();
            $table->string('event_kind', 100);
            $table->json('payload');
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_center_events');
        Schema::dropIfExists('call_center_calls');
    }
};
