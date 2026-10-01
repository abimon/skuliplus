<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = collect(Schema::getColumns('users'))->keyBy('name');

        Schema::table('users', function (Blueprint $table) use ($columns) {
            if (! $columns->has('school_id')) {
                $table->unsignedBigInteger('school_id')->nullable()->index();
            }
            if (! $columns->has('first_name')) {
                $table->string('first_name')->nullable();
            }
            if (! $columns->has('last_name')) {
                $table->string('last_name')->nullable();
            }
            if (! $columns->has('other_names')) {
                $table->string('other_names')->nullable();
            }
            if (! $columns->has('phone')) {
                $table->string('phone')->nullable();
            }
            if (! $columns->has('national_id')) {
                $table->string('national_id')->nullable();
            }
            if (! $columns->has('gender')) {
                $table->string('gender')->nullable();
            }
            if (! $columns->has('date_of_birth')) {
                $table->date('date_of_birth')->nullable();
            }
            if (! $columns->has('photo_path')) {
                $table->string('photo_path')->nullable();
            }
            if (! $columns->has('address')) {
                $table->string('address')->nullable();
            }
            if (! $columns->has('county')) {
                $table->string('county')->nullable();
            }
            if (! $columns->has('sub_county')) {
                $table->string('sub_county')->nullable();
            }
            if (! $columns->has('can_login')) {
                $table->boolean('can_login')->default(true);
            }
            if (! $columns->has('status')) {
                $table->string('status')->default('active');
            }
            if (! $columns->has('employee_number')) {
                $table->string('employee_number')->nullable();
            }
            if (! $columns->has('admission_number')) {
                $table->string('admission_number')->nullable();
            }
            if (! $columns->has('must_change_password')) {
                $table->boolean('must_change_password')->default(false);
            }
            if (! $columns->has('deleted_at')) {
                $table->softDeletes();
            }
        });

        if (! ($columns->get('email')['nullable'] ?? true)) {
            Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable()->change());
        }
        if (! ($columns->get('password')['nullable'] ?? true)) {
            Schema::table('users', fn (Blueprint $table) => $table->string('password')->nullable()->change());
        }
    }

    public function down(): void {}
};
