<?php

namespace App\Http\Controllers;

use App\Models\CentralRequest;
use App\Models\Curriculum;
use App\Models\SchoolModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchoolRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->school_id && $user->hasRole('school_admin'), 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(['module_activation', 'curriculum_activation', 'feedback', 'testimonial', 'inquiry'])],
            'subject' => 'required|string|max:190',
            'message' => 'required|string|max:5000',
            'module_key' => ['nullable', 'required_if:type,module_activation', Rule::exists('school_modules', 'key')],
            'curriculum_id' => ['nullable', 'required_if:type,curriculum_activation', 'integer', 'exists:curricula,id'],
        ]);

        if ($data['type'] === 'module_activation') {
            $school = $user->school;
            $module = SchoolModule::where('key', $data['module_key'])->firstOrFail();
            abort_if($module->is_active && $school->hasModuleEnabled($module->key), 422, 'This module is already available to your school.');
            $pending = CentralRequest::where('school_id', $user->school_id)
                ->where('type', 'module_activation')
                ->where('status', 'pending')
                ->where('payload->module_key', $data['module_key'])
                ->exists();
            if ($pending) {
                return back()->with('success', 'A request for this module is already with Central Management.');
            }
        }

        if ($data['type'] === 'curriculum_activation') {
            $school = $user->school;
            abort_unless($school, 403);
            $curriculum = Curriculum::findOrFail($data['curriculum_id']);
            $alreadyAvailable = $curriculum->is_active && $school->curricula()->whereKey($curriculum->id)->exists();
            abort_if($alreadyAvailable, 422, 'This curriculum is already available to your school.');
            $pending = CentralRequest::where('school_id', $user->school_id)
                ->where('type', 'curriculum_activation')
                ->where('status', 'pending')
                ->where('payload->curriculum_id', $curriculum->id)
                ->exists();
            if ($pending) {
                return back()->with('success', 'A request for this curriculum is already with Central Management.');
            }
        }

        CentralRequest::create([
            'school_id' => $user->school_id,
            'user_id' => $user->id,
            'type' => $data['type'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'payload' => array_filter([
                'module_key' => $data['module_key'] ?? null,
                'curriculum_id' => $data['curriculum_id'] ?? null,
            ], fn ($value) => $value !== null),
            'status' => 'pending',
        ]);

        return back()->with('success', 'Your request has been sent to Central Management.');
    }
}
