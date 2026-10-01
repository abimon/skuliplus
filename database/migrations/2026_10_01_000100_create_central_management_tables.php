<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_modules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price_kes', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('central_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40)->index();
            $table->string('subject', 190);
            $table->text('message')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });

        $now = now();
        foreach ([
            ['users', 'People', 'Learner, family and staff records', null],
            ['academics', 'Academics and curriculum', 'Classes, timetables, assessments and results', null],
            ['finance', 'Finance', 'Fee accounts, payments and receipts', null],
            ['library', 'Library', 'Books, loans and returns', null],
            ['gate', 'Gate visits', 'Visitor registration and checkout', null],
            ['stores', 'Stores', 'Inventory and stock movements', null],
            ['activities', 'Activities', 'Clubs, events and participation', null],
            ['labs', 'Laboratories', 'Laboratory equipment and supplies', null],
        ] as [$key, $name, $description, $price]) {
            DB::table('school_modules')->updateOrInsert(
                ['key' => $key],
                ['name' => $name, 'description' => $description, 'price_kes' => $price, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('central_requests');
        Schema::dropIfExists('school_modules');
    }
};
