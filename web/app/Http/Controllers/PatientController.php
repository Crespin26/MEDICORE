<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PatientController extends Controller
{
    public function index()
    {
        return Inertia::render('patients/index', ['patients' => Patient::orderBy('last_name')->orderBy('first_name')->paginate(20)]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
    }

    public function store(Request $request)
    {
        Patient::create($this->validated($request));

        return to_route('patients.index');
    }

    public function update(Request $request, Patient $patient)
    {
        $patient->update($this->validated($request));

        return to_route('patients.index');
    }
}
