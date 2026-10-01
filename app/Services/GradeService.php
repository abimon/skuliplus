<?php

namespace App\Services;

class GradeService
{
    public function fromScore(float $score, int $max = 100): array
    {
        $percent = $max > 0 ? ($score / $max) * 100 : 0;

        return match (true) {
            $percent >= 80 => ['grade' => 'EE', 'remark' => 'Exceeding Expectation'],
            $percent >= 60 => ['grade' => 'ME', 'remark' => 'Meeting Expectation'],
            $percent >= 40 => ['grade' => 'AE', 'remark' => 'Approaching Expectation'],
            default => ['grade' => 'BE', 'remark' => 'Below Expectation'],
        };
    }
}
