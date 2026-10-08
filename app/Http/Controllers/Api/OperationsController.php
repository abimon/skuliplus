<?php

namespace App\Http\Controllers\Api;

use App\Models\Activity;
use App\Models\Book;
use App\Models\BookLoan;
use App\Models\Club;
use App\Models\Lab;
use App\Models\LabItem;
use App\Models\LabMovement;
use App\Models\StoreItem;
use App\Models\StoreMovement;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\VisitorCheckoutCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Day to day operations: library, gate, stores, activities and laboratories.
 * These are the modules that benefit most from offline capture in the field.
 */
class OperationsController extends ApiController
{
    private const MODULES = [
        'library' => ['title' => 'Library', 'view' => 'library.view', 'manage' => 'library.manage'],
        'gate' => ['title' => 'Gate visits', 'view' => 'gate.view', 'manage' => 'gate.manage'],
        'stores' => ['title' => 'Stores', 'view' => 'stores.view', 'manage' => 'stores.manage'],
        'activities' => ['title' => 'Activities', 'view' => 'clubs.view', 'manage' => 'clubs.manage'],
        'labs' => ['title' => 'Laboratories', 'view' => 'labs.view', 'manage' => 'labs.manage'],
    ];

    public function index(Request $request, string $module): JsonResponse
    {
        $config = $this->moduleConfig($module);
        $school = $this->ensureSchoolModule($request, $module);
        $user = $this->user($request);

        abort_unless($user->can($config['view']), 403, 'You do not have access to this resource.');

        $records = match ($module) {
            'library' => $this->libraryRecords($school->id, $request),
            'gate' => $this->gateRecords($school->id),
            'stores' => $this->storeRecords($school->id),
            'activities' => $this->activityRecords($school->id),
            'labs' => $this->labRecords($school->id),
        };

        return $this->ok([
            'module' => $module,
            'title' => $config['title'],
            'can_manage' => $user->can($config['manage']),
            'records' => $records,
            'options' => $this->options($school->id, $module, $user->can($config['manage'])),
        ]);
    }
    public function borrowBook(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'library');

        abort_unless($this->user($request)->can('library.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'book_id' => ['required', Rule::exists('books', 'id')->where('school_id', $school->id)],
            'borrower_id' => ['required', Rule::exists('users', 'id')->where('school_id', $school->id)],
            'due_on' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $borrower = User::where('school_id', $school->id)->findOrFail($data['borrower_id']);

        if (! $borrower->hasAnyRole(['student', 'teacher', 'hod_academics', 'support_staff'])) {
            throw ValidationException::withMessages(['borrower_id' => 'Choose a learner or school staff member.']);
        }

        $loan = DB::transaction(function () use ($school, $data, $borrower) {
            $book = Book::where('school_id', $school->id)->lockForUpdate()->findOrFail($data['book_id']);

            if ($book->available < 1) {
                throw ValidationException::withMessages(['book_id' => 'No copies of this book are currently available.']);
            }

            $book->decrement('available');

            return BookLoan::create([
                'school_id' => $school->id,
                'book_id' => $book->id,
                'borrower_id' => $borrower->id,
                'borrowed_on' => today(),
                'due_on' => $data['due_on'],
                'status' => 'borrowed',
            ]);
        });

        return $this->ok($this->loanPayload($loan->fresh(['book', 'borrower'])), [], 201);
    }

    public function returnBook(Request $request, BookLoan $loan): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'library');

        abort_unless($this->user($request)->can('library.manage'), 403, 'You do not have access to this resource.');
        abort_unless($loan->school_id === $school->id, 404);

        DB::transaction(function () use ($loan, $school) {
            $lockedLoan = BookLoan::where('school_id', $school->id)->lockForUpdate()->findOrFail($loan->id);

            if ($lockedLoan->status === 'returned') {
                return;
            }

            Book::where('school_id', $school->id)->lockForUpdate()->findOrFail($lockedLoan->book_id)->increment('available');
            $lockedLoan->update(['status' => 'returned', 'returned_on' => today()]);
        });

        return $this->ok($this->loanPayload($loan->fresh(['book', 'borrower'])));
    }

    public function storeBook(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'library');

        abort_unless($this->user($request)->can('library.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'isbn' => ['nullable', 'string', 'max:40'],
            'accession_no' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:80'],
            'copies' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $book = Book::create([
            'school_id' => $school->id,
            ...$data,
            'available' => $data['copies'],
        ]);

        return $this->ok($this->bookPayload($book), [], 201);
    }
    public function checkInVisitor(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'gate');

        abort_unless($this->user($request)->can('gate.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'id_number' => ['nullable', 'string', 'max:80'],
            'purpose' => ['required', 'string', 'max:255'],
            'person_to_see' => ['nullable', 'string', 'max:160'],
        ]);

        $visitor = Visitor::create([
            'school_id' => $school->id,
            ...$data,
            'checkout_code' => strtoupper(Str::random(6)),
            'checked_in_at' => now(),
            'recorded_by' => $this->user($request)->id,
        ]);

        $this->sendCheckoutCode($visitor);

        return $this->ok($this->visitorPayload($visitor), [], 201);
    }

    public function checkoutVisitor(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'gate');

        abort_unless($this->user($request)->can('gate.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate(['checkout_code' => ['required', 'string', 'max:20']]);

        $visitor = Visitor::where('school_id', $school->id)
            ->whereNull('checked_out_at')
            ->where('checkout_code', $data['checkout_code'])
            ->first();

        if (! $visitor) {
            throw ValidationException::withMessages(['checkout_code' => 'No active visitor matches that checkout code.']);
        }

        $visitor->update(['checked_out_at' => now()]);

        return $this->ok($this->visitorPayload($visitor->fresh()));
    }

    public function storeStoreItem(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'stores');

        abort_unless($this->user($request)->can('stores.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:80'],
            'store_type' => ['required', 'string', 'max:120'],
            'unit' => ['nullable', 'string', 'max:40'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:1000000'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        return $this->ok($this->storeItemPayload(StoreItem::create(['school_id' => $school->id, ...$data])), [], 201);
    }

    public function moveStoreStock(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'stores');

        abort_unless($this->user($request)->can('stores.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'store_item_id' => ['required', Rule::exists('store_items', 'id')->where('school_id', $school->id)],
            'direction' => ['required', 'in:receive,issue'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $movement = DB::transaction(function () use ($request, $school, $data) {
            $item = StoreItem::where('school_id', $school->id)->lockForUpdate()->findOrFail($data['store_item_id']);

            if ($data['direction'] === 'issue' && $data['quantity'] > $item->quantity) {
                throw ValidationException::withMessages(['quantity' => 'Issue quantity exceeds stock on hand.']);
            }

            $item->quantity += $data['direction'] === 'receive' ? $data['quantity'] : -$data['quantity'];
            $item->save();

            return StoreMovement::create([
                'school_id' => $school->id,
                'store_item_id' => $item->id,
                'type' => $data['direction'],
                'quantity' => $data['quantity'],
                'reference' => $data['reference'] ?? null,
                'processed_by' => $request->user()->id,
            ]);
        });

        return $this->ok([
            'movement' => [
                'id' => $movement->id,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'reference' => $movement->reference,
            ],
            'item' => $this->storeItemPayload($movement->storeItem()->first()),
        ], [], 201);
    }
    public function storeClub(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'activities');

        abort_unless($this->user($request)->can('clubs.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['nullable', 'string', 'max:40'],
            'patron_id' => ['nullable', Rule::exists('users', 'id')->where('school_id', $school->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'meeting_day' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->ok(Club::create(['school_id' => $school->id, ...$data]), [], 201);
    }

    public function storeActivity(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'activities');

        abort_unless($this->user($request)->can('clubs.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'club_id' => ['nullable', Rule::exists('clubs', 'id')->where('school_id', $school->id)],
            'held_on' => ['required', 'date'],
            'venue' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->ok(Activity::create(['school_id' => $school->id, ...$data]), [], 201);
    }

    public function storeLabItem(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'labs');

        abort_unless($this->user($request)->can('labs.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'lab_id' => ['nullable', Rule::exists('labs', 'id')->where('school_id', $school->id)],
            'item_type' => ['nullable', 'string', 'max:80'],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'condition' => ['nullable', 'string', 'max:40'],
        ]);

        return $this->ok($this->labItemPayload(LabItem::create(['school_id' => $school->id, ...$data])), [], 201);
    }

    public function moveLabStock(Request $request): JsonResponse
    {
        $school = $this->ensureSchoolModule($request, 'labs');

        abort_unless($this->user($request)->can('labs.manage'), 403, 'You do not have access to this resource.');

        $data = $request->validate([
            'lab_item_id' => ['required', Rule::exists('lab_items', 'id')->where('school_id', $school->id)],
            'direction' => ['required', 'in:receive,issue'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $movement = DB::transaction(function () use ($request, $school, $data) {
            $item = LabItem::where('school_id', $school->id)->lockForUpdate()->findOrFail($data['lab_item_id']);

            if ($data['direction'] === 'issue' && $data['quantity'] > $item->quantity) {
                throw ValidationException::withMessages(['quantity' => 'Issue quantity exceeds stock on hand.']);
            }

            $item->quantity += $data['direction'] === 'receive' ? $data['quantity'] : -$data['quantity'];
            $item->status = $data['direction'] === 'issue' ? 'issued' : 'available';
            $item->save();

            return LabMovement::create([
                'school_id' => $school->id,
                'lab_item_id' => $item->id,
                'type' => $data['direction'],
                'quantity' => $data['quantity'],
                'notes' => $data['notes'] ?? null,
                'processed_by' => $request->user()->id,
            ]);
        });

        return $this->ok([
            'movement' => [
                'id' => $movement->id,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'notes' => $movement->notes,
            ],
            'item' => $this->labItemPayload($movement->labItem()->first()),
        ], [], 201);
    }
    private function moduleConfig(string $module): array
    {
        abort_unless(array_key_exists($module, self::MODULES), 404);

        return self::MODULES[$module];
    }

    private function libraryRecords(int $schoolId, Request $request): array
    {
        return [
            'books' => Book::where('school_id', $schoolId)
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q
                    ->where('title', 'like', '%'.$request->input('q').'%')
                    ->orWhere('author', 'like', '%'.$request->input('q').'%')))
                ->orderBy('title')->limit(200)->get()
                ->map(fn (Book $book) => $this->bookPayload($book))->all(),
            'loans' => BookLoan::where('school_id', $schoolId)
                ->with(['book:id,title', 'borrower:id,name,admission_number'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->latest()->limit(200)->get()
                ->map(fn (BookLoan $loan) => $this->loanPayload($loan))->all(),
        ];
    }

    private function gateRecords(int $schoolId): array
    {
        return [
            'visitors' => Visitor::where('school_id', $schoolId)
                ->with('recorder:id,name')
                ->when(request()->filled('on'), fn ($q) => $q->whereDate('checked_in_at', request()->input('on')))
                ->orderByDesc('checked_in_at')->limit(200)->get()
                ->map(fn (Visitor $visitor) => $this->visitorPayload($visitor))->all(),
        ];
    }

    private function storeRecords(int $schoolId): array
    {
        return [
            'items' => StoreItem::where('school_id', $schoolId)->orderBy('name')->get()
                ->map(fn (StoreItem $item) => $this->storeItemPayload($item))->all(),
            'movements' => StoreMovement::where('school_id', $schoolId)
                ->with('storeItem:id,name')->latest()->limit(100)->get()
                ->map(fn (StoreMovement $movement) => [
                    'id' => $movement->id,
                    'item' => $movement->storeItem?->name,
                    'type' => $movement->type,
                    'quantity' => $movement->quantity,
                    'reference' => $movement->reference,
                    'created_at' => $this->timestamped($movement->created_at),
                ])->all(),
        ];
    }

    private function activityRecords(int $schoolId): array
    {
        return [
            'clubs' => Club::where('school_id', $schoolId)->withCount('members')->orderBy('name')->get()
                ->map(fn (Club $club) => [
                    'id' => $club->id,
                    'name' => $club->name,
                    'type' => $club->type,
                    'meeting_day' => $club->meeting_day,
                    'members_count' => $club->members_count,
                ])->all(),
            'activities' => Activity::where('school_id', $schoolId)
                ->with('club:id,name')->latest('held_on')->limit(100)->get()
                ->map(fn (Activity $activity) => [
                    'id' => $activity->id,
                    'title' => $activity->title,
                    'club' => $activity->club?->name,
                    'held_on' => $activity->held_on?->toDateString(),
                    'venue' => $activity->venue,
                    'notes' => $activity->notes,
                ])->all(),
        ];
    }

    private function labRecords(int $schoolId): array
    {
        return [
            'labs' => Lab::where('school_id', $schoolId)->withCount('items')->orderBy('name')->get()
                ->map(fn (Lab $lab) => [
                    'id' => $lab->id,
                    'name' => $lab->name,
                    'discipline' => $lab->discipline,
                    'location' => $lab->location,
                    'items_count' => $lab->items_count,
                ])->all(),
            'items' => LabItem::where('school_id', $schoolId)->orderBy('name')->get()
                ->map(fn (LabItem $item) => $this->labItemPayload($item))->all(),
        ];
    }
    /**
     * Picker data the mobile forms need for each module.
 */
    private function options(int $schoolId, string $module, bool $canManage): array
    {
        if (! $canManage) {
            return [];
        }

        return match ($module) {
            'library' => [
                // Only learners and staff who physically hold books can borrow.
                'borrowers' => User::where('school_id', $schoolId)
                    ->whereHas('roles', fn ($q) => $q->whereIn('name', [
                        'student', 'teacher', 'hod_academics', 'support_staff',
                    ]))
                    ->orderBy('name')->limit(200)
                    ->get(['id', 'name', 'admission_number'])->all(),
            ],
            'activities' => [
                'clubs' => Club::where('school_id', $schoolId)->orderBy('name')->get(['id', 'name'])->all(),
            ],
            'labs' => [
                'labs' => Lab::where('school_id', $schoolId)->orderBy('name')->get(['id', 'name'])->all(),
            ],
            default => [],
        };
    }

    private function bookPayload(Book $book): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'accession_no' => $book->accession_no,
            'category' => $book->category,
            'copies' => $book->copies,
            'available' => $book->available,
            'status' => $book->status,
            'updated_at' => $this->timestamped($book->updated_at),
        ];
    }

    private function loanPayload(BookLoan $loan): array
    {
        return [
            'id' => $loan->id,
            'book' => $loan->book ? ['id' => $loan->book->id, 'title' => $loan->book->title] : null,
            'borrower' => $this->userPayload($loan->borrower),
            'borrowed_on' => $loan->borrowed_on?->toDateString(),
            'due_on' => $loan->due_on?->toDateString(),
            'returned_on' => $loan->returned_on?->toDateString(),
            'status' => $loan->status,
        ];
    }

    private function visitorPayload(Visitor $visitor): array
    {
        return [
            'id' => $visitor->id,
            'full_name' => $visitor->full_name,
            'phone' => $visitor->phone,
            'email' => $visitor->email,
            'id_number' => $visitor->id_number,
            'purpose' => $visitor->purpose,
            'person_to_see' => $visitor->person_to_see,
            'recorded_by' => $this->userPayload($visitor->recorder),
            'checkout_code' => $visitor->checkout_code,
            'checked_in_at' => $this->timestamped($visitor->checked_in_at),
            'checked_out_at' => $this->timestamped($visitor->checked_out_at),
            'on_site' => $visitor->checked_out_at === null,
        ];
    }

    private function storeItemPayload(StoreItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'sku' => $item->sku,
            'store_type' => $item->store_type,
            'unit' => $item->unit,
            'quantity' => $item->quantity,
            'reorder_level' => $item->reorder_level,
            'unit_cost' => (float) $item->unit_cost,
            'low_stock' => $item->quantity <= $item->reorder_level,
            'updated_at' => $this->timestamped($item->updated_at),
        ];
    }

    private function labItemPayload(LabItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'lab_id' => $item->lab_id,
            'item_type' => $item->item_type,
            'quantity' => $item->quantity,
            'condition' => $item->condition,
            'status' => $item->status,
            'updated_at' => $this->timestamped($item->updated_at),
        ];
    }

    /**
     * Best effort delivery of the gate checkout code.
     */
    private function sendCheckoutCode(Visitor $visitor): void
    {
        try {
            Notification::route('mail', $visitor->email ?? 'gate@'.$this->user(request())->school?->code)
                ->notify(new VisitorCheckoutCode($visitor));
        } catch (\Throwable) {
            // The gatekeeper still sees the code in the app, so a failed
            // notification must not fail the check-in.
        }
    }
}
