<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Book;
use App\Models\BookLoan;
use App\Models\Club;
use App\Models\Enrollment;
use App\Models\Lab;
use App\Models\LabItem;
use App\Models\LabMovement;
use App\Models\Promotion;
use App\Models\SchoolClass;
use App\Models\StoreItem;
use App\Models\StoreMovement;
use App\Models\Stream;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\VisitorCheckoutCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OperationsController extends Controller
{
    private const MODULES = [
        'library' => ['title' => 'Library', 'view' => 'library.view', 'manage' => 'library.manage'],
        'gate' => ['title' => 'Gate visits', 'view' => 'gate.view', 'manage' => 'gate.manage'],
        'stores' => ['title' => 'Stores', 'view' => 'stores.view', 'manage' => 'stores.manage'],
        'activities' => ['title' => 'Activities', 'view' => 'clubs.view', 'manage' => 'clubs.manage'],
        'labs' => ['title' => 'Laboratories', 'view' => 'labs.view', 'manage' => 'labs.manage'],
        'promotions' => ['title' => 'Promotions', 'view' => 'promotions.manage', 'manage' => 'promotions.manage'],
    ];

    public function index(Request $request, string $module): Response
    {
        $config = $this->moduleConfig($module);
        abort_unless($request->user()->can($config['view']), 403);
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);
        $canManage = $request->user()->can($config['manage']);
        $school = $request->user()->school()->first(['name', 'code', 'county']);

        if ($module === 'promotions') {
            abort_unless($canManage, 403);

            return Inertia::render('operations/promotions', [
                'school' => $school,
                'classes' => SchoolClass::where('school_id', $schoolId)->with(['level', 'academicYear', 'streams'])->orderByDesc('academic_year_id')->get(),
                'enrollments' => Enrollment::where('school_id', $schoolId)->where('status', 'active')
                    ->with(['student:id,name,admission_number', 'schoolClass:id,name,academic_year_id', 'stream:id,name'])
                    ->get(),
                'promotions' => Promotion::where('school_id', $schoolId)->with(['student:id,name,admission_number', 'fromClass:id,name', 'toClass:id,name'])->latest()->limit(100)->get(),
                'modules' => $this->navigation($request),
            ]);
        }

        $records = match ($module) {
            'library' => $this->libraryRecords($request, $schoolId, $canManage),
            'gate' => $this->gateRecords($schoolId),
            'stores' => $this->storeRecords($schoolId),
            'activities' => $this->activityRecords($request, $schoolId, $canManage),
            'labs' => $this->labRecords($schoolId),
        };

        return Inertia::render('operations/index', [
            'module' => $module,
            'title' => $config['title'],
            'school' => $school,
            'canManage' => $canManage,
            'records' => $records,
            'options' => $this->options($request, $schoolId, $module, $canManage),
            'modules' => $this->navigation($request),
        ]);
    }

    public function store(Request $request, string $module): RedirectResponse
    {
        $config = $this->moduleConfig($module);
        $this->authorizePermission($request, $config['manage']);
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);

        match ($module) {
            'library' => $this->createBook($request, $schoolId),
            'gate' => $this->checkInVisitor($request, $schoolId),
            'stores' => $this->createStoreItem($request, $schoolId),
            'activities' => $this->createActivityRecord($request, $schoolId),
            'labs' => $this->createLabRecord($request, $schoolId),
            default => abort(404),
        };

        return back()->with('success', $module === 'gate' ? 'Visitor checked in.' : 'Record created.');
    }

    public function borrowBook(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'library.manage');
        $schoolId = $request->user()->school_id;
        $data = $request->validate([
            'book_id' => ['required', Rule::exists('books', 'id')->where('school_id', $schoolId)],
            'borrower_id' => ['required', Rule::exists('users', 'id')->where('school_id', $schoolId)],
            'due_on' => 'required|date|after_or_equal:today',
        ]);
        $borrower = User::where('school_id', $schoolId)->findOrFail($data['borrower_id']);
        if (! $borrower->hasAnyRole(['student', 'teacher', 'hod_academics', 'support_staff'])) {
            throw ValidationException::withMessages(['borrower_id' => 'Choose a learner or school staff member.']);
        }

        DB::transaction(function () use ($schoolId, $data, $borrower) {
            $book = Book::where('school_id', $schoolId)->lockForUpdate()->findOrFail($data['book_id']);
            if ($book->available < 1) {
                throw ValidationException::withMessages(['book_id' => 'No copies of this book are currently available.']);
            }
            $book->decrement('available');
            BookLoan::create([
                'school_id' => $schoolId,
                'book_id' => $book->id,
                'borrower_id' => $borrower->id,
                'borrowed_on' => today(),
                'due_on' => $data['due_on'],
                'status' => 'borrowed',
            ]);
        });

        return back()->with('success', 'Book loan recorded.');
    }

    public function returnBook(Request $request, BookLoan $loan): RedirectResponse
    {
        $this->authorizePermission($request, 'library.manage');
        $schoolId = $request->user()->school_id;
        abort_unless($schoolId, 403);
        abort_unless($loan->school_id === $schoolId, 404);

        DB::transaction(function () use ($loan, $schoolId) {
            $lockedLoan = BookLoan::where('school_id', $schoolId)->lockForUpdate()->findOrFail($loan->id);
            if ($lockedLoan->status === 'returned') {
                return;
            }
            $book = Book::where('school_id', $schoolId)->lockForUpdate()->findOrFail($lockedLoan->book_id);
            $lockedLoan->update(['status' => 'returned', 'returned_on' => today()]);
            $book->increment('available');
        });

        return back()->with('success', 'Book return recorded.');
    }

    public function checkoutVisitor(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'gate.manage');
        $schoolId = $request->user()->school_id;
        $data = $request->validate(['checkout_code' => 'required|string|max:20']);
        $visitor = Visitor::where('school_id', $schoolId)->whereNull('checked_out_at')->where('checkout_code', $data['checkout_code'])->first();
        if (! $visitor) {
            throw ValidationException::withMessages(['checkout_code' => 'No active visitor matches that checkout code.']);
        }

        $visitor->update(['checked_out_at' => now()]);

        return back()->with('success', $visitor->full_name.' checked out.');
    }

    public function moveStoreStock(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'stores.manage');
        $schoolId = $request->user()->school_id;
        $data = $request->validate([
            'store_item_id' => ['required', Rule::exists('store_items', 'id')->where('school_id', $schoolId)],
            'direction' => 'required|in:receive,issue',
            'quantity' => 'required|integer|min:1|max:100000',
            'reference' => 'nullable|string|max:120',
        ]);

        DB::transaction(function () use ($request, $schoolId, $data) {
            $item = StoreItem::where('school_id', $schoolId)->lockForUpdate()->findOrFail($data['store_item_id']);
            if ($data['direction'] === 'issue' && $data['quantity'] > $item->quantity) {
                throw ValidationException::withMessages(['quantity' => 'Issue quantity exceeds stock on hand.']);
            }
            $item->quantity += $data['direction'] === 'receive' ? $data['quantity'] : -$data['quantity'];
            $item->save();
            StoreMovement::create([
                'school_id' => $schoolId,
                'store_item_id' => $item->id,
                'type' => $data['direction'],
                'quantity' => $data['quantity'],
                'reference' => $data['reference'] ?? null,
                'processed_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Store movement recorded.');
    }

    public function moveLabStock(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'labs.manage');
        $schoolId = $request->user()->school_id;
        $data = $request->validate([
            'lab_item_id' => ['required', Rule::exists('lab_items', 'id')->where('school_id', $schoolId)],
            'direction' => 'required|in:receive,issue',
            'quantity' => 'required|integer|min:1|max:100000',
            'notes' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($request, $schoolId, $data) {
            $item = LabItem::where('school_id', $schoolId)->lockForUpdate()->findOrFail($data['lab_item_id']);
            if ($data['direction'] === 'issue' && $data['quantity'] > $item->quantity) {
                throw ValidationException::withMessages(['quantity' => 'Issue quantity exceeds lab stock on hand.']);
            }
            $item->quantity += $data['direction'] === 'receive' ? $data['quantity'] : -$data['quantity'];
            $item->save();
            LabMovement::create([
                'school_id' => $schoolId,
                'lab_item_id' => $item->id,
                'type' => $data['direction'],
                'quantity' => $data['quantity'],
                'notes' => $data['notes'] ?? null,
                'processed_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Laboratory movement recorded.');
    }

    public function processPromotions(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'promotions.manage');
        $schoolId = $request->user()->school_id;
        $data = $request->validate([
            'from_stream_id' => ['required', Rule::exists('streams', 'id')->where('school_id', $schoolId)],
            'to_class_id' => ['required', Rule::exists('school_classes', 'id')->where('school_id', $schoolId)],
            'to_stream_id' => 'nullable|integer',
            'student_ids' => 'required|array|min:1',
            'student_ids.*' => 'required|integer|distinct',
            'notes' => 'nullable|string|max:1000',
        ]);
        $sourceStream = Stream::where('school_id', $schoolId)->with('schoolClass')->findOrFail($data['from_stream_id']);
        $targetClass = SchoolClass::where('school_id', $schoolId)->findOrFail($data['to_class_id']);
        $sourceYear = $sourceStream->schoolClass->academicYear;
        $targetYear = $targetClass->academicYear;
        if ($sourceStream->school_class_id === $targetClass->id || $sourceYear->starts_on->greaterThanOrEqualTo($targetYear->starts_on)) {
            throw ValidationException::withMessages(['to_class_id' => 'Select a class in a later academic year.']);
        }
        $targetStream = null;
        if (! empty($data['to_stream_id'])) {
            $targetStream = Stream::where('school_id', $schoolId)->where('school_class_id', $targetClass->id)->find($data['to_stream_id']);
            if (! $targetStream) {
                throw ValidationException::withMessages(['to_stream_id' => 'Choose a stream in the target class.']);
            }
            if ($targetStream->enrollments()->where('status', 'active')->count() + count($data['student_ids']) > $targetStream->capacity) {
                throw ValidationException::withMessages(['to_stream_id' => 'The target stream does not have enough capacity for this promotion group.']);
            }
        }

        DB::transaction(function () use ($request, $schoolId, $data, $sourceStream, $targetClass, $targetStream) {
            foreach ($data['student_ids'] as $studentId) {
                $enrollment = Enrollment::where('school_id', $schoolId)
                    ->where('student_id', $studentId)
                    ->where('stream_id', $sourceStream->id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();
                if (! $enrollment) {
                    throw ValidationException::withMessages(['student_ids' => 'One selected learner is not actively enrolled in the source stream.']);
                }

                Promotion::create([
                    'school_id' => $schoolId,
                    'student_id' => $studentId,
                    'from_class_id' => $sourceStream->school_class_id,
                    'from_stream_id' => $sourceStream->id,
                    'to_class_id' => $targetClass->id,
                    'to_stream_id' => $targetStream?->id,
                    'academic_year_id' => $targetClass->academic_year_id,
                    'decision' => 'promoted',
                    'notes' => $data['notes'] ?? null,
                    'processed_by' => $request->user()->id,
                ]);
                $enrollment->update(['status' => 'promoted']);
                Enrollment::create([
                    'school_id' => $schoolId,
                    'student_id' => $studentId,
                    'school_class_id' => $targetClass->id,
                    'stream_id' => $targetStream?->id,
                    'academic_year_id' => $targetClass->academic_year_id,
                    'status' => 'active',
                    'enrolled_on' => now()->toDateString(),
                ]);
            }
        });

        return back()->with('success', count($data['student_ids']).' learners promoted.');
    }

    private function moduleConfig(string $module): array
    {
        abort_unless(isset(self::MODULES[$module]), 404);

        return self::MODULES[$module];
    }

    private function libraryRecords(Request $request, int $schoolId, bool $canManage): array
    {
        $borrowers = $canManage ? collect() : $request->user()->children()->where('users.school_id', $schoolId)->pluck('users.id');

        return [
            'books' => Book::where('school_id', $schoolId)->orderBy('title')->get(['id', 'title', 'author', 'isbn', 'accession_no', 'category', 'copies', 'available', 'status']),
            'loans' => BookLoan::where('school_id', $schoolId)->when(! $canManage, fn ($query) => $query->whereIn('borrower_id', $borrowers))
                ->with(['book:id,title,author', 'borrower:id,name,admission_number'])->latest('borrowed_on')->limit(100)->get(),
        ];
    }

    private function gateRecords(int $schoolId): array
    {
        return ['visitors' => Visitor::where('school_id', $schoolId)->latest('checked_in_at')->limit(100)->get()];
    }

    private function storeRecords(int $schoolId): array
    {
        return [
            'items' => StoreItem::where('school_id', $schoolId)->orderBy('name')->get(),
            'movements' => StoreMovement::where('school_id', $schoolId)->with('item:id,name,sku')->latest()->limit(100)->get(),
        ];
    }

    private function activityRecords(Request $request, int $schoolId, bool $canManage): array
    {
        $childIds = $canManage ? collect() : $request->user()->children()->where('users.school_id', $schoolId)->pluck('users.id');
        $clubs = Club::where('school_id', $schoolId)
            ->when(! $canManage, fn ($query) => $query->whereHas('members', fn ($members) => $members->whereIn('users.id', $childIds)))
            ->with(['patron:id,name', 'activities' => fn ($activities) => $activities->latest('held_on')->limit(8)])
            ->withCount('members')->orderBy('name')->get();

        return ['clubs' => $clubs];
    }

    private function labRecords(int $schoolId): array
    {
        return ['labs' => Lab::where('school_id', $schoolId)->with(['technician:id,name', 'items' => fn ($items) => $items->orderBy('name')])->orderBy('name')->get()];
    }

    private function options(Request $request, int $schoolId, string $module, bool $canManage): array
    {
        return match ($module) {
            'library' => ['borrowers' => $canManage ? User::where('school_id', $schoolId)->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['student', 'teacher', 'hod_academics', 'support_staff']))->orderBy('name')->get(['id', 'name', 'admission_number']) : []],
            'activities' => [
                'patrons' => User::role(['teacher', 'hod_academics'])->where('school_id', $schoolId)->orderBy('name')->get(['id', 'name']),
                'borrowers' => User::role('student')->where('school_id', $schoolId)->orderBy('name')->get(['id', 'name', 'admission_number']),
            ],
            'labs' => ['technicians' => User::role('lab_technician')->where('school_id', $schoolId)->orderBy('name')->get(['id', 'name'])],
            default => [],
        };
    }

    private function createBook(Request $request, int $schoolId): void
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'isbn' => 'nullable|string|max:80',
            'accession_no' => ['nullable', 'string', 'max:80', Rule::unique('books', 'accession_no')->where('school_id', $schoolId)],
            'category' => 'nullable|string|max:100',
            'copies' => 'required|integer|min:1|max:100000',
        ]);
        Book::create(['school_id' => $schoolId, ...$data, 'available' => $data['copies'], 'status' => 'available']);
    }

    private function checkInVisitor(Request $request, int $schoolId): void
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:160',
            'id_number' => 'nullable|string|max:80',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
            'purpose' => 'required|string|max:255',
            'person_to_see' => 'nullable|string|max:160',
        ]);
        do {
            $code = Str::upper(Str::random(6));
        } while (Visitor::where('school_id', $schoolId)->where('checkout_code', $code)->exists());

        $visitor = Visitor::create([
            'school_id' => $schoolId,
            ...$data,
            'checkout_code' => $code,
            'checked_in_at' => now(),
            'recorded_by' => $request->user()->id,
        ]);
        if ($visitor->email) {
            try {
                Notification::route('mail', $visitor->email)->notify(new VisitorCheckoutCode($visitor));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function createStoreItem(Request $request, int $schoolId): void
    {
        $data = $request->validate([
            'name' => 'required|string|max:180',
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('store_items', 'sku')->where('school_id', $schoolId)],
            'store_type' => 'required|in:kitchen,furniture,uniform,stationery,other',
            'unit' => 'required|string|max:30',
            'quantity' => 'required|integer|min:0|max:1000000',
            'reorder_level' => 'required|integer|min:0|max:1000000',
            'unit_cost' => 'required|numeric|min:0|max:999999999',
        ]);
        DB::transaction(function () use ($request, $schoolId, $data) {
            $item = StoreItem::create(['school_id' => $schoolId, ...$data]);
            if ($item->quantity > 0) {
                StoreMovement::create([
                    'school_id' => $schoolId,
                    'store_item_id' => $item->id,
                    'type' => 'receive',
                    'quantity' => $item->quantity,
                    'reference' => 'Opening stock',
                    'processed_by' => $request->user()->id,
                ]);
            }
        });
    }

    private function createActivityRecord(Request $request, int $schoolId): void
    {
        $entity = $request->input('entity', 'club');
        if ($entity === 'activity') {
            $data = $request->validate([
                'club_id' => ['nullable', Rule::exists('clubs', 'id')->where('school_id', $schoolId)],
                'title' => 'required|string|max:180',
                'held_on' => 'nullable|date',
                'venue' => 'nullable|string|max:180',
                'notes' => 'nullable|string|max:1000',
            ]);
            Activity::create(['school_id' => $schoolId, ...$data]);

            return;
        }
        if ($entity === 'member') {
            $data = $request->validate([
                'club_id' => ['required', Rule::exists('clubs', 'id')->where('school_id', $schoolId)],
                'user_id' => ['required', Rule::exists('users', 'id')->where('school_id', $schoolId)],
                'role' => 'nullable|string|max:80',
            ]);
            if (! User::role('student')->where('school_id', $schoolId)->whereKey($data['user_id'])->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Choose a learner from this school.']);
            }
            Club::where('school_id', $schoolId)->findOrFail($data['club_id'])->members()->syncWithoutDetaching([$data['user_id'] => ['role' => $data['role'] ?? 'member']]);

            return;
        }

        $data = $request->validate([
            'name' => 'required|string|max:160',
            'type' => 'required|in:club,sport,arts,other',
            'patron_id' => ['nullable', Rule::exists('users', 'id')->where('school_id', $schoolId)],
            'description' => 'nullable|string|max:1000',
            'meeting_day' => 'nullable|string|max:30',
        ]);
        if (! empty($data['patron_id']) && ! User::role(['teacher', 'hod_academics'])->where('school_id', $schoolId)->whereKey($data['patron_id'])->exists()) {
            throw ValidationException::withMessages(['patron_id' => 'Choose a teacher from this school.']);
        }
        Club::create(['school_id' => $schoolId, ...$data]);
    }

    private function createLabRecord(Request $request, int $schoolId): void
    {
        if ($request->input('entity') === 'item') {
            $data = $request->validate([
                'lab_id' => ['required', Rule::exists('labs', 'id')->where('school_id', $schoolId)],
                'name' => 'required|string|max:160',
                'item_type' => 'required|in:apparatus,chemical,consumable,equipment,other',
                'quantity' => 'required|integer|min:0|max:1000000',
                'condition' => 'required|in:good,fair,poor,damaged',
            ]);
            DB::transaction(function () use ($request, $schoolId, $data) {
                $item = LabItem::create(['school_id' => $schoolId, ...$data, 'status' => 'available']);
                if ($item->quantity > 0) {
                    LabMovement::create([
                        'school_id' => $schoolId,
                        'lab_item_id' => $item->id,
                        'type' => 'receive',
                        'quantity' => $item->quantity,
                        'notes' => 'Opening stock',
                        'processed_by' => $request->user()->id,
                    ]);
                }
            });

            return;
        }
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'discipline' => 'required|string|max:80',
            'technician_id' => ['nullable', Rule::exists('users', 'id')->where('school_id', $schoolId)],
            'location' => 'nullable|string|max:160',
        ]);
        if (! empty($data['technician_id']) && ! User::role('lab_technician')->where('school_id', $schoolId)->whereKey($data['technician_id'])->exists()) {
            throw ValidationException::withMessages(['technician_id' => 'Choose a lab technician from this school.']);
        }
        Lab::create(['school_id' => $schoolId, ...$data]);
    }

    private function navigation(Request $request): array
    {
        $items = [
            ['key' => 'users', 'name' => 'People', 'permission' => 'users.view', 'href' => $request->user()->can('users.update') ? '/users' : null],
            ['key' => 'academics', 'name' => 'Academics', 'permission' => 'classes.view', 'href' => '/academics'],
            ['key' => 'promotions', 'name' => 'Promotions', 'permission' => 'promotions.manage', 'href' => '/modules/promotions'],
            ['key' => 'finance', 'name' => 'Finance', 'permission' => 'finance.view', 'href' => $request->user()->can('finance.manage') ? '/finance' : null],
            ['key' => 'library', 'name' => 'Library', 'permission' => 'library.view'],
            ['key' => 'gate', 'name' => 'Gate visits', 'permission' => 'gate.view'],
            ['key' => 'stores', 'name' => 'Stores', 'permission' => 'stores.view'],
            ['key' => 'activities', 'name' => 'Activities', 'permission' => 'clubs.view'],
            ['key' => 'labs', 'name' => 'Laboratories', 'permission' => 'labs.view'],
        ];

        return collect($items)->filter(fn (array $item) => $request->user()->can($item['permission'])
            && $request->user()->school->hasModuleEnabled($item['key'] === 'promotions' ? 'academics' : $item['key']))
            ->map(fn (array $item) => collect($item)->except('permission')->all())->values()->all();
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()->can($permission), 403);
    }
}
