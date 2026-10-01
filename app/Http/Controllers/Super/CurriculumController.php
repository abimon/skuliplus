<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\Curriculum;
use App\Models\CurriculumLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CurriculumController extends Controller
{
    public function index(): Response
    {
        abort_unless(auth()->user()->can('curricula.manage') || auth()->user()->hasRole('super_admin'), 403);

        return Inertia::render('curricula/index', [
            'curricula' => Curriculum::with(['levels', 'subjects', 'schools:id,name'])->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'alpha_dash:ascii', 'unique:curricula,code'],
            'name' => ['required', 'string', 'max:160'],
            'authority' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        Curriculum::create($data + ['is_active' => true]);

        return back()->with('success', 'Curriculum pathway created. Add its levels and learning areas next.');
    }

    public function update(Request $request, Curriculum $curriculum): RedirectResponse
    {
        $this->authorizeManage();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'alpha_dash:ascii', Rule::unique('curricula', 'code')->ignore($curriculum->id)],
            'name' => ['required', 'string', 'max:160'],
            'authority' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $curriculum->update($data);

        return back()->with('success', 'Curriculum details updated.');
    }

    public function storeLevel(Request $request, Curriculum $curriculum): RedirectResponse
    {
        $this->authorizeManage();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'alpha_dash:ascii', Rule::unique('curriculum_levels', 'code')->where('curriculum_id', $curriculum->id)],
            'name' => ['required', 'string', 'max:160'],
            'stage' => ['required', 'string', 'max:120'],
            'order' => ['required', 'integer', 'between:1,255'],
        ]);

        $curriculum->levels()->create($data);

        return back()->with('success', "{$data['name']} added to {$curriculum->name}.");
    }

    public function updateLevel(Request $request, Curriculum $curriculum, CurriculumLevel $level): RedirectResponse
    {
        $this->authorizeManage();
        abort_unless($level->curriculum_id === $curriculum->id, 404);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'alpha_dash:ascii', Rule::unique('curriculum_levels', 'code')->where('curriculum_id', $curriculum->id)->ignore($level->id)],
            'name' => ['required', 'string', 'max:160'],
            'stage' => ['required', 'string', 'max:120'],
            'order' => ['required', 'integer', 'between:1,255'],
        ]);

        $level->update($data);

        return back()->with('success', 'Curriculum level updated.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('curricula.manage') || auth()->user()->hasRole('super_admin'), 403);
    }
}
