<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Curriculum;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Stream;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSchoolSeeder extends Seeder
{
    public function run(): void
    {
        $super = User::updateOrCreate(
            ['email' => 'superadmin@schoolms.ke'],
            [
                'first_name' => 'National',
                'last_name' => 'Director',
                'name' => 'National Director',
                'can_login' => true,
                'status' => 'active',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $super->syncRoles(['super_admin']);

        $school = School::updateOrCreate(
            ['code' => 'UHS001'],
            [
                'name' => 'Umoja Heights School',
                'type' => 'mixed',
                'level' => 'junior_senior',
                'county' => 'Nairobi',
                'sub_county' => 'Westlands',
                'phone' => '0710000000',
                'email' => 'info@umojaheights.ke',
                'primary_color' => '#0f766e',
                'secondary_color' => '#f59e0b',
                'accent_color' => '#1d4ed8',
                'motto' => 'Elimu ni Nguvu',
                'mission' => 'To nurture competent, ethical and innovative learners.',
                'vision' => 'A leading CBC school producing globally competitive citizens.',
                'aim' => 'Holistic education for every child.',
                'po_box' => 'P.O. Box 123-00100 Nairobi',
            ]
        );

        $cbc = Curriculum::where('code', 'CBC')->first();
        $eight = Curriculum::where('code', '844')->first();
        $school->curricula()->syncWithoutDetaching([
            $cbc->id => ['is_primary' => true],
            $eight->id => ['is_primary' => false],
        ]);

        $admin = User::updateOrCreate(
            ['email' => 'admin@umojaheights.ke'],
            [
                'school_id' => $school->id,
                'first_name' => 'Amina',
                'last_name' => 'Otieno',
                'name' => 'Amina Otieno',
                'phone' => '0711111111',
                'can_login' => true,
                'status' => 'active',
                'employee_number' => 'EMP001',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $admin->syncRoles(['school_admin']);

        $hod = User::updateOrCreate(
            ['email' => 'hod@umojaheights.ke'],
            [
                'school_id' => $school->id,
                'first_name' => 'Peter',
                'last_name' => 'Mwangi',
                'name' => 'Peter Mwangi',
                'can_login' => true,
                'status' => 'active',
                'employee_number' => 'EMP002',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $hod->syncRoles(['hod_academics']);
        $hod->teacherProfile()->updateOrCreate(
            ['user_id' => $hod->id],
            ['school_id' => $school->id, 'rank' => 'hod', 'tsc_number' => 'TSC1001']
        );

        $teacher = User::updateOrCreate(
            ['email' => 'teacher@umojaheights.ke'],
            [
                'school_id' => $school->id,
                'first_name' => 'Faith',
                'last_name' => 'Wanjiku',
                'name' => 'Faith Wanjiku',
                'can_login' => true,
                'status' => 'active',
                'employee_number' => 'EMP003',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $teacher->syncRoles(['teacher']);
        $teacher->teacherProfile()->updateOrCreate(
            ['user_id' => $teacher->id],
            ['school_id' => $school->id, 'rank' => 'teacher', 'tsc_number' => 'TSC1002']
        );

        $parent = User::updateOrCreate(
            ['email' => 'parent@umojaheights.ke'],
            [
                'school_id' => $school->id,
                'first_name' => 'Joseph',
                'last_name' => 'Kamau',
                'name' => 'Joseph Kamau',
                'phone' => '0722000000',
                'can_login' => true,
                'status' => 'active',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $parent->syncRoles(['parent']);
        $parent->parentProfile()->updateOrCreate(
            ['user_id' => $parent->id],
            ['school_id' => $school->id, 'occupation' => 'Engineer', 'relationship' => 'father']
        );

        $student = User::updateOrCreate(
            ['admission_number' => 'UHS-2026-001', 'school_id' => $school->id],
            [
                'first_name' => 'Brian',
                'last_name' => 'Kamau',
                'name' => 'Brian Kamau',
                'email' => null,
                'gender' => 'male',
                'can_login' => false,
                'status' => 'active',
                'password' => null,
            ]
        );
        $student->syncRoles(['student']);
        $student->studentProfile()->updateOrCreate(
            ['user_id' => $student->id],
            [
                'school_id' => $school->id,
                'nemis_number' => 'NEMIS001',
                'year_admitted' => 2024,
                'boarding_status' => 'day',
            ]
        );
        $parent->children()->syncWithoutDetaching([$student->id => ['relationship' => 'father', 'is_primary' => true]]);

        foreach ([
            ['librarian@umojaheights.ke', 'Grace', 'Njeri', 'librarian', 'LIB001'],
            ['lab@umojaheights.ke', 'Daniel', 'Omondi', 'lab_technician', 'LAB001'],
            ['gate@umojaheights.ke', 'John', 'Mutua', 'gatekeeper', 'GAT001'],
            ['finance@umojaheights.ke', 'Mercy', 'Achieng', 'finance_officer', 'FIN001'],
            ['stores@umojaheights.ke', 'Ali', 'Hassan', 'stores_officer', 'STO001'],
        ] as [$email, $first, $last, $role, $emp]) {
            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'school_id' => $school->id,
                    'first_name' => $first,
                    'last_name' => $last,
                    'name' => "$first $last",
                    'can_login' => true,
                    'status' => 'active',
                    'employee_number' => $emp,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
            $user->syncRoles([$role]);
            $user->staffProfile()->updateOrCreate(
                ['user_id' => $user->id],
                ['school_id' => $school->id, 'staff_category' => $role]
            );
        }

        $year = AcademicYear::updateOrCreate(
            ['school_id' => $school->id, 'name' => '2026'],
            ['starts_on' => '2026-01-06', 'ends_on' => '2026-11-20', 'is_current' => true]
        );

        $term = Term::updateOrCreate(
            ['school_id' => $school->id, 'academic_year_id' => $year->id, 'number' => 1],
            ['name' => 'Term 1', 'starts_on' => '2026-01-06', 'ends_on' => '2026-04-10', 'is_current' => true]
        );

        $level = $cbc->levels()->where('code', 'G7')->first();
        $class = SchoolClass::updateOrCreate(
            ['school_id' => $school->id, 'name' => 'Grade 7', 'academic_year_id' => $year->id],
            [
                'curriculum_id' => $cbc->id,
                'curriculum_level_id' => $level->id,
            ]
        );

        $stream = Stream::updateOrCreate(
            ['school_id' => $school->id, 'school_class_id' => $class->id, 'name' => 'East'],
            ['class_teacher_id' => $teacher->id, 'capacity' => 45]
        );

        Enrollment::updateOrCreate(
            ['student_id' => $student->id, 'academic_year_id' => $year->id],
            [
                'school_id' => $school->id,
                'school_class_id' => $class->id,
                'stream_id' => $stream->id,
                'status' => 'active',
                'enrolled_on' => now(),
            ]
        );

        Department::updateOrCreate(
            ['school_id' => $school->id, 'code' => 'ACAD'],
            ['name' => 'Academics', 'hod_id' => $hod->id]
        );
    }
}
