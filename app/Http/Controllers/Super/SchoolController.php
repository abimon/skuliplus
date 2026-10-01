<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\Curriculum;
use App\Models\School;
use App\Models\SchoolModule;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SchoolController extends Controller
{
    public function index(): Response
    {
        $this->authorizePermission('schools.view');

        return Inertia::render('schools/index', [
            'schools' => School::with('curricula')->withCount('users')->latest()->get(),
            'curricula' => Curriculum::where('is_active', true)->get(['id', 'code', 'name']),
            'modules' => School::MODULES,
            'availableModules' => SchoolModule::where('is_active', true)->orderBy('key')->get(['key', 'name', 'description', 'price_kes']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission('schools.create');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:40|unique:schools,code',
            'type' => 'nullable|string',
            'level' => 'nullable|string',
            'county' => 'nullable|string',
            'sub_county' => 'nullable|string',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'enabled_modules' => ['required', 'array', 'min:1'],
            'enabled_modules.*' => [
                'required',
                'string',
                Rule::in(array_keys(School::MODULES)),
                Rule::exists('school_modules', 'key')->where('is_active', true),
            ],
            'curriculum_ids' => 'required|array|min:1',
            'curriculum_ids.*' => 'exists:curricula,id',
            'admin_name' => 'required|string',
            'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'nullable|string|min:8',
        ]);

        if (! in_array('users', $data['enabled_modules'], true)) {
            throw ValidationException::withMessages(['enabled_modules' => 'The People module is required for every school.']);
        }

        $school = School::create(collect($data)->except(['curriculum_ids', 'admin_name', 'admin_email', 'admin_password'])->all());
        $ids = collect($data['curriculum_ids'])->mapWithKeys(fn ($id, $i) => [$id => ['is_primary' => $i === 0]]);
        $school->curricula()->sync($ids);

        $names = explode(' ', $data['admin_name'], 2);
        $password = $data['admin_password'] ?? Str::password(10);
        $admin = User::create([
            'school_id' => $school->id,
            'first_name' => $names[0],
            'last_name' => $names[1] ?? $names[0],
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'can_login' => true,
            'status' => 'active',
            'password' => Hash::make($password),
            'must_change_password' => true,
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('school_admin');

        return back()->with('success', "School registered. Admin login: {$admin->email} / {$password}");
    }

    public function update(Request $request, School $school): RedirectResponse
    {
        $this->authorizePermission('schools.update');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:40', 'unique:schools,code,'.$school->id],
            'type' => 'required|string|max:80',
            'level' => 'required|string|max:120',
            'moe_code' => 'nullable|string|max:80',
            'knec_code' => 'nullable|string|max:80',
            'county' => 'nullable|string|max:120',
            'sub_county' => 'nullable|string|max:120',
            'ward' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:80',
            'email' => 'nullable|email|max:190',
            'website' => 'nullable|url|max:255',
            'po_box' => 'nullable|string|max:120',
            'motto' => 'nullable|string|max:255',
            'mission' => 'nullable|string|max:5000',
            'vision' => 'nullable|string|max:5000',
            'aim' => 'nullable|string|max:5000',
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => 'nullable|image|max:2048',
            'remove_logo' => 'nullable|boolean',
            'is_active' => 'boolean',
            'enabled_modules' => ['present', 'array'],
            'enabled_modules.*' => ['string', 'in:'.implode(',', array_keys(School::MODULES))],
            'curriculum_ids' => 'sometimes|array',
            'curriculum_ids.*' => 'exists:curricula,id',
        ]);

        if ($request->has('curriculum_ids')) {
            $this->authorizePermission('schools.assign-curriculum');
        }

        if ($request->hasFile('logo')) {
            if ($school->logo_path) {
                Storage::disk('public')->delete($school->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('logos', 'public');
        } elseif ($request->boolean('remove_logo')) {
            if ($school->logo_path) {
                Storage::disk('public')->delete($school->logo_path);
            }
            $data['logo_path'] = null;
        }

        unset($data['logo'], $data['remove_logo']);
        $school->update(collect($data)->except('curriculum_ids')->all());

        if ($request->has('curriculum_ids')) {
            $ids = collect($data['curriculum_ids'])->mapWithKeys(fn ($id, $i) => [$id => ['is_primary' => $i === 0]]);
            $school->curricula()->sync($ids);
        }

        return back()->with('success', 'School updated.');
    }

    public function setActive(Request $request, School $school): RedirectResponse
    {
        $this->authorizePermission('schools.update');
        $data = $request->validate(['is_active' => 'required|boolean']);
        $school->update($data);

        return back()->with('success', $school->is_active ? 'School activated.' : 'School suspended.');
    }

    private function authorizePermission(string $permission): void
    {
        abort_unless(Auth::user()->can($permission), 403);
    }
}
