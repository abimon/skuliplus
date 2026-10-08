<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->profilePayload($this->user($request)));
    }

    /**
     * Update the fields a user is allowed to change about themselves.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'gender' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'county' => ['nullable', 'string', 'max:120'],
            'sub_county' => ['nullable', 'string', 'max:120'],
        ]);

        $data['name'] = trim($data['first_name'].' '.$data['last_name']);

        if (empty($data['email'])) {
            $data['email'] = null;
        }

        $user->update($data);

        return $this->ok($this->profilePayload($user->fresh()), [], 200);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! $user->password || ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
        ]);

        return $this->ok(['password_updated' => true]);
    }

    /**
     * A user may delete their own account; students must be removed by staff.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $this->user($request);

        abort_if($user->hasAnyRole(['super_admin', 'school_admin']), 403, 'Contact central management to deactivate this account.');

        $user->delete();

        return $this->ok(['deactivated' => true]);
    }

    /**
     * Session for the staff who manage people: confirm identity before bulk edits.
     */
    public function confirmIdentity(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate(['password' => ['required', 'string']]);

        abort_unless($user->password && Hash::check($data['password'], $user->password), 422, 'The password is incorrect.');

        return $this->ok(['confirmed' => true]);
    }

    /**
     * A minimal directory entry used when a profile needs to link to another user.
     */
    public function directory(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $query = User::query()
            ->when($user->school_id, fn ($q) => $q->where('school_id', $user->school_id))
            ->where('id', '!=', $user->id)
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$request->input('q').'%')
                ->orWhere('email', 'like', '%'.$request->input('q').'%')
                ->orWhere('admission_number', 'like', '%'.$request->input('q').'%')))
            ->orderBy('name')
            ->limit(min((int) $request->integer('limit', 25), 100));

        return $this->ok($query->get()->map(fn (User $u) => $this->userPayload($u))->all());
    }
}