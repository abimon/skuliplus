<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('school_modules')->where('key', 'call_center')->update([
            'description' => 'Inbound and outbound calls and call history',
            'updated_at' => now(),
        ]);

        Schema::table('call_center_calls', function (Blueprint $table) {
            $table->string('direction', 16)->default('outbound')->after('school_id');
            $table->string('provider_number_id', 100)->nullable()->after('session_id')->index();
        });

        Schema::create('call_center_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('provider_number_id', 100)->unique();
            $table->uuid('agent_id');
            $table->string('agent_mode', 20)->default('always');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_center_numbers');

        Schema::table('call_center_calls', function (Blueprint $table) {
            $table->dropColumn(['direction', 'provider_number_id']);
        });

        DB::table('school_modules')->where('key', 'call_center')->update([
            'description' => 'Outbound calls and call history',
            'updated_at' => now(),
        ]);
    }
};
