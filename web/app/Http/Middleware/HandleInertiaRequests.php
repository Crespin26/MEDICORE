<?php

namespace App\Http\Middleware;

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
            'status' => fn () => $request->session()->get('status'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
                'permissions' => collect(['dashboard.view', 'patients.view', 'patients.write', 'users.manage', 'appointments.view', 'appointments.write', 'appointments.status', 'availabilities.manage', 'consultations.view', 'consultations.write', 'medical-record.view', 'medical-record.write', 'billing.view', 'billing.manage', 'billing.pay', 'billing.reverse', 'catalog.manage'])->filter(fn ($permission) => $request->user()?->can($permission))->values()->all(),
            ],
        ]);
    }
}
