<?php

namespace App\Http\Controllers;

use App\Services\DashboardReport;
use App\Support\ReportingPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardReport $service)
    {
        [$start, $end] = ReportingPeriod::from($request);

        return Inertia::render('dashboard', [...$service->build($request->user(), $start, $end), 'csrfToken' => $request->user()->can('administration.export') ? csrf_token() : null]);
    }
}
