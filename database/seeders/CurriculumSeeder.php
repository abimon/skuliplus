<?php

namespace Database\Seeders;

use App\Models\Curriculum;
use App\Models\CurriculumLevel;
use App\Models\Subject;
use Illuminate\Database\Seeder;

class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        $cbc = Curriculum::updateOrCreate(
            ['code' => 'CBC'],
            [
                'name' => 'Competency Based Curriculum',
                'authority' => 'KICD',
                'description' => 'Kenya Competency Based Curriculum designed by the Kenya Institute of Curriculum Development.',
            ]
        );

        $eight = Curriculum::updateOrCreate(
            ['code' => '844'],
            [
                'name' => '8-4-4 System',
                'authority' => 'KICD',
                'description' => '8-4-4 basic education structure still used in some secondary pathways.',
            ]
        );

        $cbcLevels = [
            ['code' => 'PP1', 'name' => 'Pre-Primary 1', 'stage' => 'Pre-Primary', 'order' => 1],
            ['code' => 'PP2', 'name' => 'Pre-Primary 2', 'stage' => 'Pre-Primary', 'order' => 2],
            ['code' => 'G1', 'name' => 'Grade 1', 'stage' => 'Lower Primary', 'order' => 3],
            ['code' => 'G2', 'name' => 'Grade 2', 'stage' => 'Lower Primary', 'order' => 4],
            ['code' => 'G3', 'name' => 'Grade 3', 'stage' => 'Lower Primary', 'order' => 5],
            ['code' => 'G4', 'name' => 'Grade 4', 'stage' => 'Upper Primary', 'order' => 6],
            ['code' => 'G5', 'name' => 'Grade 5', 'stage' => 'Upper Primary', 'order' => 7],
            ['code' => 'G6', 'name' => 'Grade 6', 'stage' => 'Upper Primary', 'order' => 8],
            ['code' => 'G7', 'name' => 'Grade 7', 'stage' => 'Junior School', 'order' => 9],
            ['code' => 'G8', 'name' => 'Grade 8', 'stage' => 'Junior School', 'order' => 10],
            ['code' => 'G9', 'name' => 'Grade 9', 'stage' => 'Junior School', 'order' => 11],
            ['code' => 'G10', 'name' => 'Grade 10', 'stage' => 'Senior School', 'order' => 12],
            ['code' => 'G11', 'name' => 'Grade 11', 'stage' => 'Senior School', 'order' => 13],
            ['code' => 'G12', 'name' => 'Grade 12', 'stage' => 'Senior School', 'order' => 14],
        ];

        foreach ($cbcLevels as $level) {
            CurriculumLevel::updateOrCreate(
                ['curriculum_id' => $cbc->id, 'code' => $level['code']],
                $level
            );
        }

        $eightLevels = [
            ['code' => 'STD1', 'name' => 'Standard 1', 'stage' => 'Primary', 'order' => 1],
            ['code' => 'STD2', 'name' => 'Standard 2', 'stage' => 'Primary', 'order' => 2],
            ['code' => 'STD3', 'name' => 'Standard 3', 'stage' => 'Primary', 'order' => 3],
            ['code' => 'STD4', 'name' => 'Standard 4', 'stage' => 'Primary', 'order' => 4],
            ['code' => 'STD5', 'name' => 'Standard 5', 'stage' => 'Primary', 'order' => 5],
            ['code' => 'STD6', 'name' => 'Standard 6', 'stage' => 'Primary', 'order' => 6],
            ['code' => 'STD7', 'name' => 'Standard 7', 'stage' => 'Primary', 'order' => 7],
            ['code' => 'STD8', 'name' => 'Standard 8', 'stage' => 'Primary', 'order' => 8],
            ['code' => 'F1', 'name' => 'Form 1', 'stage' => 'Secondary', 'order' => 9],
            ['code' => 'F2', 'name' => 'Form 2', 'stage' => 'Secondary', 'order' => 10],
            ['code' => 'F3', 'name' => 'Form 3', 'stage' => 'Secondary', 'order' => 11],
            ['code' => 'F4', 'name' => 'Form 4', 'stage' => 'Secondary', 'order' => 12],
        ];

        foreach ($eightLevels as $level) {
            CurriculumLevel::updateOrCreate(
                ['curriculum_id' => $eight->id, 'code' => $level['code']],
                $level
            );
        }

        $cbcSubjects = [
            ['ENG', 'English', 'Languages', true, 5],
            ['KIS', 'Kiswahili', 'Languages', true, 5],
            ['MAT', 'Mathematics', 'STEM', true, 6],
            ['INTSCI', 'Integrated Science', 'STEM', true, 5],
            ['SST', 'Social Studies', 'Humanities', true, 4],
            ['CRE', 'Christian Religious Education', 'Humanities', false, 3],
            ['IRE', 'Islamic Religious Education', 'Humanities', false, 3],
            ['AGR', 'Agriculture', 'STEM', true, 3],
            ['PRETECH', 'Pre-Technical Studies', 'STEM', true, 3],
            ['CAS', 'Creative Arts and Sports', 'Arts', true, 3],
            ['CS', 'Computer Science', 'STEM', false, 3],
            ['ENV', 'Environmental Activities', 'Integrated', true, 4],
            ['HYG', 'Hygiene and Nutrition', 'Integrated', true, 3],
            ['HSC', 'Home Science', 'Applied', false, 3],
        ];

        foreach ($cbcSubjects as [$code, $name, $area, $core, $lessons]) {
            Subject::updateOrCreate(
                ['curriculum_id' => $cbc->id, 'code' => $code],
                [
                    'name' => $name,
                    'learning_area' => $area,
                    'is_core' => $core,
                    'weekly_lessons' => $lessons,
                ]
            );
        }

        $eightSubjects = [
            ['ENG', 'English', true, 6],
            ['KIS', 'Kiswahili', true, 5],
            ['MAT', 'Mathematics', true, 6],
            ['BIO', 'Biology', true, 4],
            ['CHEM', 'Chemistry', true, 4],
            ['PHY', 'Physics', true, 4],
            ['HIST', 'History and Government', true, 3],
            ['GEO', 'Geography', true, 3],
            ['CRE', 'CRE', false, 3],
            ['BST', 'Business Studies', false, 3],
            ['AGR', 'Agriculture', false, 3],
            ['COMP', 'Computer Studies', false, 3],
        ];

        foreach ($eightSubjects as [$code, $name, $core, $lessons]) {
            Subject::updateOrCreate(
                ['curriculum_id' => $eight->id, 'code' => $code],
                [
                    'name' => $name,
                    'learning_area' => 'Core',
                    'is_core' => $core,
                    'weekly_lessons' => $lessons,
                ]
            );
        }
    }
}
