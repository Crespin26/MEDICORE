<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DoctorAvailability;
use App\Models\Patient;
use App\Models\PersonnelEvent;
use App\Models\ProfessionalProfile;
use App\Models\StaffAbsence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PersonnelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
        config(['clinic.timezone' => 'Africa/Porto-Novo']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->actingAs($this->admin);
    }

    private function profile(array $extra = []): array
    {
        return array_replace(['employee_number' => 'EMP-01', 'job_title' => 'Médecin fictif', 'department' => 'Service fictif', 'specialties' => ['Spécialité déclarée'], 'professional_phone' => '00000000', 'license_number' => 'DECLARED-01', 'started_on' => '2026-01-01', 'version' => 0, 'reason' => 'Création de la fiche fictive'], $extra);
    }

    private function absence(array $extra = []): array
    {
        return array_replace(['starts_at' => '2026-11-02T09:00', 'ends_at' => '2026-11-02T10:00', 'category' => 'leave', 'reason' => 'Motif administratif fictif'], $extra);
    }

    private function prepareBooking(): array
    {
        $patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
        DoctorAvailability::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-02 07:00:00', 'ends_at' => '2026-11-02 16:00:00']);

        return ['doctor_id' => $this->doctor->id, 'patient_id' => $patient->id, 'starts_at' => '2026-11-02T09:00', 'ends_at' => '2026-11-02T09:30'];
    }

    public function test_admin_creates_linked_profile_without_changing_account_role(): void
    {
        $this->patch('/personnel/'.$this->doctor->id, $this->profile(['role' => 'admin', 'status' => 'suspended', 'user_id' => $this->admin->id]))->assertRedirect()->assertSessionHasNoErrors();
        $profile = ProfessionalProfile::firstOrFail();
        $this->assertSame($this->doctor->id, $profile->user_id);
        $this->assertSame('doctor', $this->doctor->fresh()->role);
        $this->assertSame('active', $this->doctor->fresh()->status);
        $this->assertSame(['Spécialité déclarée'], $profile->specialties);
        $this->assertDatabaseHas('personnel_events', ['user_id' => $this->doctor->id, 'actor_id' => $this->admin->id, 'action' => 'profile.created']);
    }

    public function test_profile_changes_are_versioned_and_previous_values_remain_in_events(): void
    {
        $url = '/personnel/'.$this->doctor->id;
        $this->patch($url, $this->profile())->assertSessionHasNoErrors();
        $this->patch($url, $this->profile(['version' => 1, 'job_title' => 'Nouvelle fonction', 'reason' => 'Correction fictive']))->assertSessionHasNoErrors();
        $this->patch($url, $this->profile(['version' => 1]))->assertSessionHasErrors('version');
        $this->assertSame(2, ProfessionalProfile::firstOrFail()->version);
        $this->assertSame('Médecin fictif', PersonnelEvent::where('action', 'profile.created')->first()->snapshot['profile']['job_title']);
        $this->assertDatabaseCount('personnel_events', 2);
    }

    public function test_duplicate_employee_number_and_bad_specialties_are_rejected(): void
    {
        $this->patch('/personnel/'.$this->doctor->id, $this->profile())->assertSessionHasNoErrors();
        $this->patch('/personnel/'.$this->admin->id, $this->profile())->assertSessionHasErrors('employee_number');
        $this->patch('/personnel/'.$this->admin->id, $this->profile(['employee_number' => 'OTHER', 'specialties' => ['same', 'same']]))->assertSessionHasErrors('specialties.0');
    }

    public function test_directory_filters_and_own_read_only_view(): void
    {
        $this->patch('/personnel/'.$this->doctor->id, $this->profile())->assertSessionHasNoErrors();
        $this->get('/personnel?search=EMP-01&role=doctor&status=active')->assertInertia(fn (Assert $page) => $page->component('personnel/index', false)->has('staff.data', 1)->where('staff.data.0.id', $this->doctor->id));
        $this->actingAs($this->doctor)->get('/personnel/me')->assertInertia(fn (Assert $page) => $page->component('personnel/show', false)->where('member.id', $this->doctor->id)->where('canManage', false)->missing('events')->where('profile.employee_number', 'EMP-01'));
    }

    public function test_non_admin_cannot_list_read_others_or_modify_professional_information(): void
    {
        foreach (['doctor', 'secretary', 'cashier', 'staff'] as $role) {
            $user = $role === 'doctor' ? $this->doctor : User::factory()->create(['role' => $role]);
            $this->actingAs($user);
            $this->get('/personnel')->assertForbidden();
            $this->get('/personnel/'.$this->doctor->id)->assertForbidden();
            $this->patch('/personnel/'.$user->id, $this->profile())->assertForbidden();
            $this->post('/personnel/'.$user->id.'/absences', $this->absence())->assertForbidden();
            $this->get('/personnel/me')->assertStatus($role === 'staff' ? 403 : 200);
        }
        $this->assertDatabaseCount('professional_profiles', 0);
    }

    public function test_multiday_absence_utc_conversion_and_own_visibility(): void
    {
        $this->post('/personnel/'.$this->doctor->id.'/absences', $this->absence(['ends_at' => '2026-11-04T18:00']))->assertRedirect()->assertSessionHasNoErrors();
        $absence = StaffAbsence::firstOrFail();
        $this->assertSame('2026-11-02 08:00', $absence->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-11-04 17:00', $absence->ends_at->format('Y-m-d H:i'));
        $this->actingAs($this->doctor)->get('/personnel/me')->assertInertia(fn (Assert $page) => $page->has('absences.data', 1)->where('absences.data.0.starts_at', '2026-11-02T09:00')->missing('events'));
    }

    public function test_absence_blocks_booking_and_calendar_never_exposes_private_reason(): void
    {
        $data = $this->prepareBooking();
        $this->post('/personnel/'.$this->doctor->id.'/absences', $this->absence())->assertSessionHasNoErrors();
        $this->post('/appointments', $data)->assertSessionHasErrors('starts_at');
        $this->actingAs(User::factory()->create(['role' => 'secretary']))->get('/appointments?date=2026-11-02')->assertInertia(fn (Assert $page) => $page->has('absences', 1)->missing('absences.0.reason')->missing('absences.0.category'))->assertDontSee('Motif administratif fictif');
    }

    public function test_active_booking_blocks_absence_until_moved_or_cancelled(): void
    {
        $data = $this->prepareBooking();
        $this->post('/appointments', $data)->assertSessionHasNoErrors();
        $this->post('/personnel/'.$this->doctor->id.'/absences', $this->absence())->assertSessionHasErrors('starts_at');
        $this->assertDatabaseCount('staff_absences', 0);
        $appointment = Appointment::firstOrFail();
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'cancelled'])->assertSessionHasNoErrors();
        $this->post('/personnel/'.$this->doctor->id.'/absences', $this->absence())->assertSessionHasNoErrors();
    }

    public function test_cancellation_retains_history_and_reopens_booking(): void
    {
        $data = $this->prepareBooking();
        $this->post('/personnel/'.$this->doctor->id.'/absences', $this->absence())->assertSessionHasNoErrors();
        $absence = StaffAbsence::firstOrFail();
        $url = '/personnel/'.$this->doctor->id.'/absences/'.$absence->id.'/cancel';
        $this->post($url, ['version' => 9, 'reason' => 'Erreur'])->assertSessionHasErrors('version');
        $this->post($url, ['version' => 1, 'reason' => 'Période corrigée'])->assertSessionHasNoErrors();
        $this->post($url, ['version' => 1, 'reason' => 'Nouvelle soumission'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $absence->fresh()->status);
        $this->assertSame(2, PersonnelEvent::count());
        $this->post('/appointments', $data)->assertSessionHasNoErrors();
    }

    public function test_absence_overlap_is_rejected_but_adjacent_intervals_are_allowed(): void
    {
        $this->post('/personnel/'.$this->doctor->id.'/absences', $this->absence())->assertSessionHasNoErrors();
        $this->post('/personnel/'.$this->doctor->id.'/absences', $this->absence(['starts_at' => '2026-11-02T09:30', 'ends_at' => '2026-11-02T11:00']))->assertSessionHasErrors('starts_at');
        $this->post('/personnel/'.$this->doctor->id.'/absences', $this->absence(['starts_at' => '2026-11-02T10:00', 'ends_at' => '2026-11-02T11:00']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('staff_absences', 2);
    }

    public function test_new_availability_cannot_cover_an_absence(): void
    {
        $this->post('/personnel/'.$this->doctor->id.'/absences', $this->absence())->assertSessionHasNoErrors();
        $this->actingAs($this->doctor)->post('/availabilities', ['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-02T08:00', 'ends_at' => '2026-11-02T17:00'])->assertSessionHasErrors('starts_at');
    }

    public function test_wrong_user_cancel_invalid_dates_and_missing_reason_are_rejected(): void
    {
        $url = '/personnel/'.$this->doctor->id.'/absences';
        $this->post($url, $this->absence(['ends_at' => '2026-11-02T08:59']))->assertSessionHasErrors('ends_at');
        $this->post($url, $this->absence(['starts_at' => '2026-11-01T08:00', 'ends_at' => '2026-11-01T18:00']))->assertSessionHasErrors('ends_at');
        $this->post($url, $this->absence(['reason' => '']))->assertSessionHasErrors('reason');
        $this->post($url, $this->absence())->assertSessionHasNoErrors();
        $absence = StaffAbsence::firstOrFail();
        $this->post('/personnel/'.$this->admin->id.'/absences/'.$absence->id.'/cancel', ['version' => 1, 'reason' => 'Erreur'])->assertNotFound();
        $this->actingAs($this->doctor)->post($url.'/'.$absence->id.'/cancel', ['version' => 1, 'reason' => 'Non autorisé'])->assertForbidden();
    }

    public function test_user_with_professional_record_cannot_delete_account(): void
    {
        $this->patch('/personnel/'.$this->doctor->id, $this->profile())->assertSessionHasNoErrors();
        $this->actingAs($this->doctor)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
    }

    public function test_booking_cannot_be_moved_into_an_absence(): void
    {
        $data = $this->prepareBooking();
        $this->post('/appointments', array_replace($data, ['starts_at' => '2026-11-02T10:30', 'ends_at' => '2026-11-02T11:00']))->assertSessionHasNoErrors();
        $appointment = Appointment::firstOrFail();
        $this->post('/personnel/'.$this->doctor->id.'/absences', $this->absence())->assertSessionHasNoErrors();
        $this->patch('/appointments/'.$appointment->id, $data)->assertSessionHasErrors('starts_at');
        $this->assertSame('09:30', $appointment->fresh()->starts_at->format('H:i'));
    }

    public function test_failed_administrative_audit_rolls_back_profile_creation(): void
    {
        config(['app.debug' => false]);
        PersonnelEvent::creating(function () {
            throw new \RuntimeException('Simulated event failure');
        });
        try {
            $this->patch('/personnel/'.$this->doctor->id, $this->profile())->assertStatus(500);
            $this->assertDatabaseCount('professional_profiles', 0);
        } finally {
            PersonnelEvent::flushEventListeners();
        }
    }

    public function test_former_admin_referenced_as_audit_author_cannot_delete_account(): void
    {
        $this->patch('/personnel/'.$this->doctor->id, $this->profile())->assertSessionHasNoErrors();
        $this->admin->forceFill(['role' => 'cashier'])->save();
        $this->actingAs($this->admin)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
    }

    public function test_suspended_user_and_guest_cannot_access_self_profile(): void
    {
        $this->app['auth']->forgetGuards();
        $this->get('/personnel/me')->assertRedirect('/login');
        $this->doctor->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($this->doctor)->get('/personnel/me')->assertRedirect('/login');
    }
}
