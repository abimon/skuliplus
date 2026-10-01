<?php

namespace App\Http\Controllers;

use App\Models\CentralRequest;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\SchoolModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CentralManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeCentral($request);
        $requestType = $request->query('type');
        $requestTypes = ['module_activation', 'curriculum_activation', 'demo', 'contact', 'feedback', 'testimonial', 'inquiry'];
        abort_if($requestType && ! in_array($requestType, $requestTypes, true), 404);
        $requestQuery = CentralRequest::with(['school:id,name,code', 'requester:id,name,email'])
            ->when($requestType, fn ($query) => $query->where('type', $requestType))
            ->latest();

        return Inertia::render('admin/management', [
            'modules' => SchoolModule::orderBy('key')->get(),
            'curricula' => Curriculum::withCount('schools')->orderBy('name')->get(),
            'requests' => $requestQuery->paginate(25)->withQueryString(),
            'requestFilter' => $requestType ?? 'all',
            'stats' => [
                'pending' => CentralRequest::where('status', 'pending')->count(),
                'pending_modules' => CentralRequest::where('status', 'pending')->where('type', 'module_activation')->count(),
                'schools' => School::count(),
                'active_schools' => School::where('is_active', true)->count(),
            ],
        ]);
    }

    public function updateModule(Request $request, SchoolModule $module): RedirectResponse
    {
        $this->authorizeCentral($request);
        $data = $request->validate([
            'price_kes' => [$module->key === 'users' ? 'prohibited' : 'required', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'is_active' => 'required|boolean',
        ]);
        $module->update($data);

        return back()->with('success', "{$module->name} pricing and availability updated.");
    }

    public function updateCurriculum(Request $request, Curriculum $curriculum): RedirectResponse
    {
        $this->authorizeCentral($request);
        $data = $request->validate(['is_active' => 'required|boolean']);
        $curriculum->update($data);

        return back()->with('success', "{$curriculum->name} availability updated.");
    }

    public function handleRequest(Request $request, CentralRequest $centralRequest): RedirectResponse
    {
        $this->authorizeCentral($request);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'decline', 'close'])],
            'resolution_note' => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($request, $centralRequest, $data) {
            $centralRequest = CentralRequest::lockForUpdate()->findOrFail($centralRequest->id);
            abort_if($centralRequest->status !== 'pending', 409, 'This request has already been handled.');

            if ($data['decision'] === 'approve' && $centralRequest->school_id) {
                $this->applyActivation($centralRequest);
            }

            $centralRequest->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : ($data['decision'] === 'decline' ? 'declined' : 'closed'),
                'handled_by' => $request->user()->id,
                'handled_at' => now(),
                'resolution_note' => $data['resolution_note'] ?? null,
            ]);
        });

        return back()->with('success', 'Central request updated.');
    }

    private function applyActivation(CentralRequest $request): void
    {
        $school = School::findOrFail($request->school_id);
        $payload = $request->payload ?? [];

        if ($request->type === 'module_activation') {
            $module = SchoolModule::where('key', $payload['module_key'] ?? '')->firstOrFail();
            $module->update(['is_active' => true]);
            $school->update(['enabled_modules' => array_values(array_unique([...$school->enabledModuleKeys(), $module->key]))]);
        }

        if ($request->type === 'curriculum_activation') {
            $curriculum = Curriculum::findOrFail($payload['curriculum_id'] ?? 0);
            $curriculum->update(['is_active' => true]);
            $school->curricula()->syncWithoutDetaching([$curriculum->id => ['is_primary' => ! $school->curricula()->exists()]]);
        }
    }

    private function authorizeCentral(Request $request): void
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);
    }
}
