<?php

namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BrandingController extends Controller
{
    public function edit(Request $request): Response
    {
        abort_unless($request->user()->can('branding.update'), 403);
        abort_unless($request->user()->school_id, 403);

        return Inertia::render('branding/edit', [
            'school' => $request->user()->school()->firstOrFail(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('branding.update'), 403);

        $school = School::findOrFail($request->user()->school_id);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'county' => 'nullable|string|max:120',
            'sub_county' => 'nullable|string|max:120',
            'ward' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:255',
            'motto' => 'nullable|string|max:255',
            'mission' => 'nullable|string|max:5000',
            'vision' => 'nullable|string|max:5000',
            'aim' => 'nullable|string|max:5000',
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'phone' => 'nullable|string|max:80',
            'email' => 'nullable|email|max:190',
            'website' => 'nullable|url|max:255',
            'po_box' => 'nullable|string|max:120',
            'logo' => 'nullable|image|max:2048',
            'remove_logo' => 'nullable|boolean',
        ]);

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
        $school->update($data);

        return back()->with('success', 'Branding saved. It will appear on receipts and result slips.');
    }
}
