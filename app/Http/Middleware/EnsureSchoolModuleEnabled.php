<?php

namespace App\Http\Middleware;

use App\Models\School;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSchoolModuleEnabled
{
    private const ROUTE_MODULES = [
        'people' => 'users',
        'academics' => 'academics',
        'finance' => 'finance',
        'library' => 'library',
        'gate' => 'gate',
        'stores' => 'stores',
        'activities' => 'activities',
        'labs' => 'labs',
        'promotions' => 'academics',
    ];

    public function handle(Request $request, Closure $next, ?string $module = null): Response
    {
        $user = $request->user();
        if (! $user || $user->hasRole('super_admin')) {
            return $next($request);
        }

        $module ??= self::ROUTE_MODULES[(string) $request->route('module')] ?? null;
        if (! $module) {
            abort(404);
        }

        $school = $user->school;
        abort_unless($school, 404);

        if (! $school->hasModuleEnabled($module)) {
            $moduleName = $this->moduleName($request, $module);

            return redirect()->route('module.unavailable')->with('unavailable_module', [
                'key' => $module,
                'name' => $moduleName,
                'schoolName' => $school->name,
                'schoolCode' => $school->code,
            ]);
        }

        return $next($request);
    }

    private function moduleName(Request $request, string $module): string
    {
        if ($request->route('module') === 'promotions' || str_starts_with((string) $request->route()->getName(), 'promotions.')) {
            return 'Promotions';
        }

        return School::MODULES[$module] ?? ucfirst(str_replace('_', ' ', $module));
    }
}
