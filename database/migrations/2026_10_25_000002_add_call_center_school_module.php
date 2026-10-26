<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('school_modules')->updateOrInsert(
            ['key' => 'call_center'],
            [
                'name' => 'Call center',
                'description' => 'Outbound calls and call history',
                'price_kes' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        DB::table('school_modules')->where('key', 'call_center')->delete();
    }
};
