<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\AdministrationExport;
use App\Models\Appointment;
use App\Models\BillingEvent;
use App\Models\ClinicSettingEvent;
use App\Models\Consultation;
use App\Models\DoctorAvailability;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MedicalAccessEvent;
use App\Models\MedicalDocument;
use App\Models\MedicalRecordEntry;
use App\Models\PersonnelEvent;
use App\Models\ProfessionalProfile;
use App\Models\StaffAbsence;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        if ($user->role === 'admin') {
            throw ValidationException::withMessages(['password' => 'Un compte administrateur ne peut pas être supprimé depuis cet écran.']);
        }

        if (AdministrationExport::where('actor_id', $user->id)->exists() || ClinicSettingEvent::where('actor_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['password' => 'Ce compte est référencé dans l’historique administratif. Contactez un administrateur pour le suspendre.']);
        }
        if (ProfessionalProfile::where('user_id', $user->id)->exists() || StaffAbsence::where('user_id', $user->id)->exists() || PersonnelEvent::where(fn ($query) => $query->where('user_id', $user->id)->orWhere('actor_id', $user->id))->exists()) {
            throw ValidationException::withMessages(['password' => 'Ce compte possède un dossier professionnel. Contactez un administrateur pour le suspendre.']);
        }
        if (BillingEvent::where('actor_id', $user->id)->exists() || Invoice::where('created_by', $user->id)->exists() || InvoicePayment::where('recorded_by', $user->id)->exists()) {
            throw ValidationException::withMessages(['password' => 'Ce compte possède des opérations financières. Contactez un administrateur pour le suspendre.']);
        }
        if (MedicalAccessEvent::where('actor_id', $user->id)->exists() || MedicalRecordEntry::where('doctor_id', $user->id)->exists() || MedicalDocument::where('doctor_id', $user->id)->exists() || Consultation::where('doctor_id', $user->id)->exists() || Appointment::where('doctor_id', $user->id)->exists() || DoctorAvailability::where('doctor_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['password' => 'Ce compte possède un agenda ou des consultations. Contactez un administrateur pour le suspendre.']);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
