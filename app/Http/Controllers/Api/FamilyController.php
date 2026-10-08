<?php

namespace App\Http\Controllers\Api;

use App\Models\BookLoan;
use App\Models\Club;
use App\Models\Enrollment;
use App\Models\ExamResult;
use App\Models\FeeInvoice;
use App\Models\Payment;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The parent and learner interface: everything a family needs to follow a
 * child's progress. Every query is constrained to the caller's own children.
 */
class FamilyController extends ApiController
{
    public function children(Request $request): JsonResponse
    {
        $school = $this->ensureSchool($request);
        $user = $this->user($request);

        if ($user->hasRole('parent')) {
            $children = $user->children()
                ->where('users.school_id', $school->id)
                ->with(['currentEnrollment.schoolClass', 'currentEnrollment.stream'])
                ->orderBy('name')->get();
        } elseif ($user->hasRole('student')) {
            $children = collect([$user->load('currentEnrollment.schoolClass', 'currentEnrollment.stream')]);
        } else {
            abort(403, 'This interface is only available to parents and learners.');
        }

        return $this->ok($children->map(fn (User $child) => $this->childSummary($child))->all());
    }

    /**
     * Everything about one child in a single screen: results, fees, books,
     * clubs and this week's timetable.
     */
    public function child(Request $request, User $student): JsonResponse
    {
        $school = $this->ensureSchool($request);
        $user = $this->user($request);

        abort_unless($student->school_id === $school->id, 404);
        $this->assertMayViewChild($request, $student);

        $student->load(['currentEnrollment.schoolClass', 'currentEnrollment.stream', 'studentProfile']);

        $enrollment = $student->currentEnrollment;
        $streamId = $enrollment?->stream_id;

        return $this->ok([
            'child' => $this->childSummary($student),
            'results' => ExamResult::with(['exam.term', 'subject'])
                ->where('student_id', $student->id)->latest()->limit(50)->get()
                ->map(fn (ExamResult $result) => [
                    'id' => $result->id,
                    'exam' => $result->exam?->name,
                    'term' => $result->exam?->term?->name,
                    'subject' => $result->subject?->name,
                    'score' => (float) $result->score,
                    'grade' => $result->grade,
                    'remarks' => $result->remarks,
                ])->all(),
            'invoices' => FeeInvoice::where('student_id', $student->id)
                ->with(['account:id,name', 'term:id,name'])->latest()->limit(50)->get()
                ->map(fn (FeeInvoice $invoice) => [
                    'id' => $invoice->id,
                    'account' => $invoice->account?->name,
                    'term' => $invoice->term?->name,
                    'amount' => (float) $invoice->amount,
                    'balance' => (float) $invoice->balance,
                    'status' => $invoice->status,
                    'due_on' => $invoice->due_on?->toDateString(),
                ])->all(),
            'payments' => Payment::where('student_id', $student->id)
                ->with('account:id,name')->latest('paid_at')->limit(50)->get()
                ->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'receipt_number' => $payment->receipt_number,
                    'account' => $payment->account?->name,
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    'paid_at' => $this->timestamped($payment->paid_at),
                ])->all(),
            'books' => BookLoan::with('book:id,title')->where('borrower_id', $student->id)
                ->latest()->limit(50)->get()
                ->map(fn (BookLoan $loan) => [
                    'id' => $loan->id,
                    'title' => $loan->book?->title,
                    'borrowed_on' => $loan->borrowed_on?->toDateString(),
                    'due_on' => $loan->due_on?->toDateString(),
                    'returned_on' => $loan->returned_on?->toDateString(),
                    'status' => $loan->status,
                ])->all(),
            'clubs' => $student->clubs()->get(['clubs.id', 'clubs.name', 'clubs.type', 'clubs.meeting_day'])
                ->map(fn (Club $club) => [
                    'id' => $club->id,
                    'name' => $club->name,
                    'type' => $club->type,
                    'meeting_day' => $club->meeting_day,
                    'role' => $club->pivot->role ?? 'member',
                ])->all(),
            'timetable' => $streamId
                ? TimetableSlot::where('school_id', $school->id)
                    ->where('stream_id', $streamId)
                    ->with(['lesson.subject', 'lesson.teacher:id,name'])
                    ->orderBy('day_of_week')->orderBy('start_time')->get()
                    ->map(fn (TimetableSlot $slot) => [
                        'day' => $slot->day_of_week,
                        'subject' => $slot->lesson?->subject?->name,
                        'teacher' => $slot->lesson?->teacher?->name,
                        'start_time' => $slot->start_time,
                        'end_time' => $slot->end_time,
                        'room' => $slot->room,
                    ])->all()
                : [],
            'totals' => [
                'fees_balance' => (float) FeeInvoice::where('student_id', $student->id)->sum('balance'),
                'books_on_loan' => BookLoan::where('borrower_id', $student->id)->where('status', 'borrowed')->count(),
                'average_score' => (float) ExamResult::where('student_id', $student->id)->avg('score'),
            ],
        ]);
    }
    /**
     * Guard so a parent can only ever read their own children.
     */
    private function assertMayViewChild(Request $request, User $student): void
    {
        $user = $this->user($request);

        if ($user->hasRole('student')) {
            abort_unless($user->id === $student->id, 403, 'You do not have access to this resource.');

            return;
        }

        abort_unless(
            $user->children()->where('users.school_id', $user->school_id)->whereKey($student->id)->exists(),
            403,
            'You do not have access to this resource.'
        );
    }

    private function childSummary(User $student): array
    {
        $enrollment = Enrollment::with(['schoolClass', 'stream'])
            ->where('student_id', $student->id)->where('status', 'active')->latest()->first();

        return [
            'id' => $student->id,
            'name' => $student->name,
            'admission_number' => $student->admission_number,
            'photo_path' => $student->photo_path,
            'class' => $enrollment?->schoolClass?->name,
            'stream' => $enrollment?->stream?->name,
            'fees_balance' => (float) FeeInvoice::where('student_id', $student->id)->sum('balance'),
            'books_on_loan' => BookLoan::where('borrower_id', $student->id)->where('status', 'borrowed')->count(),
        ];
    }
}
