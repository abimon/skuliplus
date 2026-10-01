<?php

namespace App\Http\Middleware;

use App\Models\CentralRequest;
use App\Models\SchoolModule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'roles' => $request->user()?->getRoleNames() ?? [],
            'canManageSchoolSetup' => (bool) ($request->user()?->school_id && $request->user()->can('branding.update')),
            'navigation' => fn () => $this->navigation($request),
            'centralInboxCount' => fn () => $request->user()?->hasRole('super_admin')
                ? CentralRequest::where('status', 'pending')->count()
                : 0,
        ]);
    }

    private function navigation(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        if ($user->hasRole('super_admin')) {
            return [
                ['key' => 'dashboard', 'name' => 'Overview', 'href' => '/dashboard'],
                ['key' => 'schools', 'name' => 'Schools', 'href' => '/admin/schools'],
                ['key' => 'curricula', 'name' => 'Curricula', 'href' => '/admin/curricula'],
                ['key' => 'management', 'name' => 'Central management', 'href' => '/admin/management', 'badge' => (string) CentralRequest::where('status', 'pending')->count()],
            ];
        }

        $school = $user->school;
        if (! $school) {
            return [['key' => 'dashboard', 'name' => 'Overview', 'href' => '/dashboard']];
        }

        $routes = [
            'users' => ['People', 'users.update', '/users'],
            'academics' => ['Academics', 'classes.manage', '/academics'],
            'finance' => ['Finance', 'finance.view', '/finance'],
            'library' => ['Library', 'library.view', '/modules/library'],
            'gate' => ['Gate visits', 'gate.view', '/modules/gate'],
            'stores' => ['Stores', 'stores.view', '/modules/stores'],
            'activities' => ['Activities', 'clubs.view', '/modules/activities'],
            'labs' => ['Laboratories', 'labs.view', '/modules/labs'],
        ];
        $globallyActive = SchoolModule::where('is_active', true)->pluck('key')->all();

        $navigation = [['key' => 'dashboard', 'name' => 'Overview', 'href' => '/dashboard']];
        foreach ($routes as $key => [$name, $permission, $href]) {
            if ($school->hasModuleEnabled($key) && in_array($key, $globallyActive, true) && $user->can($permission)) {
                $navigation[] = ['key' => $key, 'name' => $name, 'href' => $href];
            }
        }
        if ($school->hasModuleEnabled('academics') && in_array('academics', $globallyActive, true) && $user->can('promotions.manage')) {
            $navigation[] = ['key' => 'promotions', 'name' => 'Promotions', 'href' => '/modules/promotions'];
        }
        if ($user->can('branding.update')) {
            $navigation[] = ['key' => 'school-setup', 'name' => 'School setup', 'href' => '/settings/school'];
        }

        return $navigation;
    }
}
