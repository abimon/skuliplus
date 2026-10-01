<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectInactiveSchoolUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->school_id || $user->hasRole('super_admin') || ($user->school && $user->school->is_active)) {
            return $next($request);
        }

        $schoolName = $user->school?->name ?? 'Your school';
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('inactive_school_name', $schoolName);

        return redirect()->route('school.inactive');
    }
}
