<?php

namespace App\Http\Controllers\Api;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\BookLoan;
use App\Models\CentralRequest;
use App\Models\Club;
use App\Models\Curriculum;
use App\Models\Department;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\FeeInvoice;
use App\Models\FinanceAccount;
use App\Models\LabItem;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Promotion;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\SchoolModule;
use App\Models\StoreItem;
use App\Models\Stream;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Models\Visitor;
use App\Services\PendingOperationHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Delta synchronisation for the mobile app.
 *
 * `pull` returns everything that changed since a client supplied cursor so
 * an offline device can refresh its cache cheaply. `push` replays mutations
 * the device queued while it had no connectivity.
 */
class SyncController extends ApiController
{
    /**
     * School scoped resources a client may request, mapped to their query.
     *
     * @return array<string, callable(int, Carbon): Builder>
     */
    private function resources(): array
    {
        $changed = fn (string $model) => fn (int $schoolId, Carbon $since) => $model::where('school_id', $schoolId)
            ->where('updated_at', '>', $since)->orderBy('updated_at');

        return [
            'people' => $changed(User::class),
            'classes' => $changed(SchoolClass::class),
            'streams' => $changed(Stream::class),
            'enrollments' => $changed(Enrollment::class),
            'timetable' => $changed(TimetableSlot::class),
            'lessons' => $changed(Lesson::class),
            'exams' => $changed(Exam::class),
            'results' => $changed(ExamResult::class),
            'invoices' => $changed(FeeInvoice::class),
            'payments' => $changed(Payment::class),
            'finance_accounts' => $changed(FinanceAccount::class),
            'books' => $changed(Book::class),
            'loans' => $changed(BookLoan::class),
            'visitors' => $changed(Visitor::class),
            'store_items' => $changed(StoreItem::class),
            'lab_items' => $changed(LabItem::class),
            'clubs' => $changed(Club::class),
            'promotions' => $changed(Promotion::class),
        ];
    }

    /**
     * Reference data that is not scoped to a single school.
     *
     * @return array<string, callable(): Builder>
     */
    private function reference(): array
    {
        return [
            'terms' => fn () => Term::orderByDesc('starts_on'),
            'academic_years' => fn () => AcademicYear::orderByDesc('starts_on'),
            'subjects' => fn () => Subject::orderBy('name'),
            'departments' => fn () => Department::orderBy('name'),
            'modules' => fn () => SchoolModule::orderBy('key'),
            'curricula' => fn () => Curriculum::orderBy('name'),
        ];
    }
    /**
     * Return changed records since the client's cursor.
     *
     * The client sends `?since=<ISO8601>` (or `resources=a,b`). Records that
     * were deleted since then are listed separately so the client can evict
     * them from its local cache.
     */
    public function pull(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $since = $request->filled('since')
            ? Carbon::parse($request->input('since'))
            : now()->subDay();

        $requested = $request->filled('resources')
            ? array_map('trim', explode(',', (string) $request->input('resources')))
            : array_keys($this->resources());

        $unknown = array_diff($requested, array_merge(array_keys($this->resources()), array_keys($this->reference())));
        abort_if($unknown, 422, 'Unknown sync resource: '.implode(', ', $unknown));

        $serverTime = now();
        $changes = [];

        if ($user->school_id) {
            $schoolId = $user->school_id;

            foreach (array_intersect($requested, array_keys($this->resources())) as $resource) {
                if (! $this->maySync($user, $resource)) {
                    continue;
                }

                $rows = ($this->resources()[$resource])($schoolId, $since)->limit(1000)->get();

                $changes[$resource] = [
                    'upserts' => $rows->map(fn ($row) => $this->rowPayload($resource, $row))->all(),
                    'deleted' => $this->deletedIds($resource, $schoolId, $since),
                ];
            }
        }

        foreach (array_intersect($requested, array_keys($this->reference())) as $resource) {
            if (! $this->maySyncReference($user, $resource)) {
                continue;
            }

            $rows = ($this->reference()[$resource])()
                ->when($user->school_id, fn ($q) => $q->where('school_id', $user->school_id))
                ->limit(1000)->get();

            $changes[$resource] = [
                'upserts' => $rows->map(fn ($row) => $this->rowPayload($resource, $row))->all(),
                'deleted' => [],
            ];
        }

        return $this->ok($changes, [
            'cursor' => $serverTime->toIso8601String(),
            'requested' => $requested,
            'skipped' => $this->skipped($user, $requested),
            'full_resync' => false,
        ]);
    }

    /**
     * Replay queued mutations captured while the device was offline.
     *
     * Each entry is processed independently so one bad record does not block
     * the rest of the queue; the client keeps only the entries that failed.
     */
    public function push(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:100'],
            'operations.*.id' => ['required', 'string', 'max:64'],
            'operations.*.action' => ['required', 'in:check_in_visitor,checkout_visitor,borrow_book,return_book,move_store_stock,move_lab_stock,store_result'],
            'operations.*.payload' => ['required', 'array'],
        ]);

        $handler = app(PendingOperationHandler::class);
        $results = [];

        foreach ($data['operations'] as $operation) {
            $results[] = $handler->handle($request, $operation['id'], $operation['action'], $operation['payload']);
        }

        $applied = collect($results)->where('status', 'applied')->count();

        return $this->ok($results, [
            'applied' => $applied,
            'rejected' => count($results) - $applied,
            'cursor' => now()->toIso8601String(),
        ]);
    }

    /**
     * A cheap endpoint the client uses to decide whether a full pull is worth
     * it, and to confirm the token is still valid.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return $this->ok([
            'user_id' => $user->id,
            'primary_role' => $this->primaryRole($user),
            'school_id' => $user->school_id,
            'enabled_modules' => $user->school?->enabledModuleKeys() ?? [],
            'available_resources' => $this->allowedResources($user),
            'server_time' => now()->toIso8601String(),
        ]);
    }
    /**
     * Map a resource to the permission that unlocks it.
     *
     * @var array<string, string>
     */
    private const RESOURCE_PERMISSIONS = [
        'people' => 'users.view',
        'classes' => 'classes.view',
        'streams' => 'classes.view',
        'enrollments' => 'classes.view',
        'timetable' => 'timetable.view',
        'lessons' => 'timetable.view',
        'exams' => 'exams.view',
        'results' => 'exams.view',
        'invoices' => 'finance.view',
        'payments' => 'finance.view',
        'finance_accounts' => 'finance.view',
        'books' => 'library.view',
        'loans' => 'library.view',
        'visitors' => 'gate.view',
        'store_items' => 'stores.view',
        'lab_items' => 'labs.view',
        'clubs' => 'clubs.view',
        'promotions' => 'promotions.manage',
    ];

    /**
     * Resources a user may synchronise at all.
     */
    private function allowedResources(User $user): array
    {
        return array_values(array_filter(
            array_keys($this->resources()),
            fn (string $resource) => $this->maySync($user, $resource)
        ));
    }

    /**
     * A resource is syncsable when the caller holds the backing permission,
     * plus a few extra grants for parents and learners.
     */
    private function maySync(User $user, string $resource): bool
    {
        $permission = self::RESOURCE_PERMISSIONS[$resource] ?? null;

        if ($permission && $user->can($permission)) {
            return true;
        }

        // Parents may cache their own children's results, fees and loans.
        if ($user->hasRole('parent') && in_array($resource, ['results', 'invoices', 'payments', 'loans', 'books'], true)) {
            return true;
        }

        // A learner may cache their own academic record.
        if ($user->hasRole('student') && in_array($resource, ['results', 'invoices', 'loans', 'books', 'classes', 'timetable'], true)) {
            return true;
        }

        return false;
    }

    private function maySyncReference(User $user, string $resource): bool
    {
        if ($resource === 'modules' || $resource === 'curricula') {
            return $user->hasAnyRole(self::CENTRAL_ROLES) || (bool) $user->school_id;
        }

        return (bool) $user->school_id;
    }

    /**
     * Resources the client asked for but is not allowed to receive.
     */
    private function skipped(User $user, array $requested): array
    {
        return array_values(array_filter(
            $requested,
            fn (string $resource) => array_key_exists($resource, $this->resources())
                ? ! $this->maySync($user, $resource)
                : (array_key_exists($resource, $this->reference()) && ! $this->maySyncReference($user, $resource))
        ));
    }

    /**
     * Ids that were hard deleted since the cursor, so the client can evict
     * them. Only resources that soft delete are considered.
     */
    private function deletedIds(string $resource, int $schoolId, Carbon $since): array
    {
        $model = $this->modelFor($resource);

        if (! $model || ! in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses($model), true)) {
            return [];
        }

        return $model::onlyTrashed()
            ->where('school_id', $schoolId)
            ->where('deleted_at', '>', $since)
            ->pluck('id')
            ->all();
    }

    private function modelFor(string $resource): ?string
    {
        return match ($resource) {
            'people' => User::class,
            'classes' => SchoolClass::class,
            'streams' => Stream::class,
            'enrollments' => Enrollment::class,
            'timetable' => TimetableSlot::class,
            'lessons' => Lesson::class,
            'exams' => Exam::class,
            'results' => ExamResult::class,
            'invoices' => FeeInvoice::class,
            'payments' => Payment::class,
            'finance_accounts' => FinanceAccount::class,
            'books' => Book::class,
            'loans' => BookLoan::class,
            'visitors' => Visitor::class,
            'store_items' => StoreItem::class,
            'lab_items' => LabItem::class,
            'clubs' => Club::class,
            'promotions' => Promotion::class,
            'terms' => Term::class,
            'academic_years' => AcademicYear::class,
            'departments' => Department::class,
            default => null,
        };
    }

    /**
     * Normalise a record for the client cache. Relations are intentionally
     * flat and keyed by id so the client can merge records cheaply.
     */
    private function rowPayload(string $resource, $row): array
    {
        $payload = $row->attributesToArray();
        $payload['id'] = $row->getKey();
        $payload['_resource'] = $resource;
        $payload['_synced_at'] = now()->toIso8601String();

        foreach (['created_at', 'updated_at', 'deleted_at'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->timestamped(
                    $row->{$field} instanceof \DateTimeInterface ? $row->{$field} : null
                );
            }
        }

        return $payload;
    }
}
