<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'schools.view', 'schools.create', 'schools.update', 'schools.assign-curriculum',
            'curricula.manage',
            'branding.update',
            'users.view', 'users.create', 'users.update', 'users.import', 'users.id-cards',
            'classes.view', 'classes.manage',
            'timetable.view', 'timetable.generate', 'timetable.edit',
            'exams.view', 'exams.manage', 'results.enter', 'results.upload',
            'promotions.manage',
            'finance.view', 'finance.manage', 'payments.record',
            'library.view', 'library.manage',
            'gate.view', 'gate.manage',
            'stores.view', 'stores.manage',
            'clubs.view', 'clubs.manage',
            'labs.view', 'labs.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $map = [
            'super_admin' => $permissions,
            'school_admin' => array_filter($permissions, fn ($p) => ! str_starts_with($p, 'schools.') && $p !== 'curricula.manage'),
            'hod_academics' => [
                'users.view', 'classes.view', 'classes.manage', 'timetable.view', 'timetable.generate', 'timetable.edit',
                'exams.view', 'exams.manage', 'results.enter', 'results.upload', 'promotions.manage',
            ],
            'teacher' => ['users.view', 'classes.view', 'timetable.view', 'exams.view', 'results.enter'],
            'parent' => ['users.view', 'exams.view', 'finance.view', 'library.view', 'clubs.view'],
            'student' => [],
            'librarian' => ['library.view', 'library.manage', 'users.view'],
            'lab_technician' => ['labs.view', 'labs.manage'],
            'gatekeeper' => ['gate.view', 'gate.manage'],
            'finance_officer' => ['finance.view', 'finance.manage', 'payments.record', 'users.view'],
            'stores_officer' => ['stores.view', 'stores.manage'],
            'support_staff' => [],
        ];

        foreach ($map as $role => $perms) {
            $roleModel = Role::findOrCreate($role);
            $roleModel->syncPermissions($perms);
        }
    }
}
