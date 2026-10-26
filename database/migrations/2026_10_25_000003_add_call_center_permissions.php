<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissionIds = [];

        foreach (['call_center.view', 'call_center.manage'] as $permission) {
            $permissionIds[$permission] = DB::table('permissions')
                ->where('name', $permission)
                ->where('guard_name', 'web')
                ->value('id');

            if (! $permissionIds[$permission]) {
                $permissionIds[$permission] = DB::table('permissions')->insertGetId([
                    'name' => $permission,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (['super_admin', 'school_admin', 'support_staff'] as $roleName) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
            if (! $roleId) {
                continue;
            }

            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['call_center.view', 'call_center.manage'])
            ->where('guard_name', 'web')
            ->pluck('id');

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
