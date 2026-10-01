<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\Stream;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TimetableSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimetableGenerator
{
    public function generate(Stream $stream, Term $term, int $periods = 8): int
    {
        return DB::transaction(function () use ($stream, $term, $periods) {
            $class = $stream->schoolClass()->with('curriculum')->firstOrFail();
            $subjects = Subject::where('curriculum_id', $class->curriculum_id)
                ->where(fn ($query) => $query->whereNull('curriculum_level_id')->orWhere('curriculum_level_id', $class->curriculum_level_id))
                ->orderBy('name')
                ->get();
            if ($subjects->isEmpty()) {
                throw ValidationException::withMessages(['term_id' => 'Add learning areas to this curriculum before generating a timetable.']);
            }

            $lessons = [];
            foreach ($subjects as $subject) {
                $lessons[] = Lesson::firstOrCreate([
                    'school_id' => $stream->school_id,
                    'stream_id' => $stream->id,
                    'subject_id' => $subject->id,
                    'term_id' => $term->id,
                ]);
            }

            $queue = [];
            foreach ($lessons as $lesson) {
                $weekly = max(1, $lesson->subject->weekly_lessons ?? 4);
                for ($index = 0; $index < $weekly; $index++) {
                    $queue[] = $lesson;
                }
            }

            TimetableSlot::where('school_id', $stream->school_id)
                ->where('stream_id', $stream->id)
                ->where('term_id', $term->id)
                ->delete();

            $created = 0;
            $slotIndex = 0;
            foreach ([1, 2, 3, 4, 5] as $day) {
                for ($period = 1; $period <= $periods; $period++) {
                    $lesson = $queue[$slotIndex % max(count($queue), 1)] ?? null;
                    $slotIndex++;

                    TimetableSlot::create([
                        'school_id' => $stream->school_id,
                        'stream_id' => $stream->id,
                        'lesson_id' => $lesson?->id,
                        'term_id' => $term->id,
                        'day_of_week' => $day,
                        'period' => $period,
                        'starts_at' => sprintf('%02d:00:00', 7 + $period),
                        'ends_at' => sprintf('%02d:40:00', 7 + $period),
                    ]);
                    $created++;
                }
            }

            return $created;
        });
    }
}
