<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DoctorAvailability;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clinic.timezone' => 'Africa/Porto-Novo']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 06:00:00', 'UTC'));
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
        DoctorAvailability::create(['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-02 07:00:00', 'ends_at' => '2026-11-02 16:00:00']);
    }

    private function actor(string $role = 'admin'): User
    {
        $user = $role === 'doctor' ? $this->doctor : User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    private function data(array $extra = []): array
    {
        return array_replace(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-02T09:00', 'ends_at' => '2026-11-02T09:30'], $extra);
    }

    private function book(array $extra = []): Appointment
    {
        $this->post('/appointments', $this->data($extra))->assertRedirect()->assertSessionHasNoErrors();

        return Appointment::latest('id')->firstOrFail();
    }

    public function test_secretary_can_book_and_times_are_stored_in_utc(): void
    {
        $this->actor('secretary');
        $appointment = $this->book(['status' => 'completed']);
        $this->assertSame('2026-11-02 08:00:00', $appointment->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('scheduled', $appointment->status);
        $this->get('/appointments?date=2026-11-02')->assertInertia(fn (Assert $page) => $page->component('appointments/index', false)->has('calendar', 7)->where('appointments.data.0.starts_at', '2026-11-02T09:00')->where('timezone', 'Africa/Porto-Novo'));
    }

    public function test_overlap_is_rejected_but_adjacent_slots_are_allowed(): void
    {
        $this->actor();
        $this->book();
        foreach ([['09:00', '09:30'], ['08:45', '09:15'], ['09:15', '09:45'], ['08:30', '10:00']] as [$start, $end]) {
            $this->post('/appointments', $this->data(['starts_at' => '2026-11-02T'.$start, 'ends_at' => '2026-11-02T'.$end]))->assertSessionHasErrors('starts_at');
        }
        $this->book(['starts_at' => '2026-11-02T09:30', 'ends_at' => '2026-11-02T10:00']);
        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_patient_cannot_be_double_booked_with_another_doctor(): void
    {
        $this->actor();
        $this->book();
        $other = User::factory()->create(['role' => 'doctor']);
        DoctorAvailability::create(['doctor_id' => $other->id, 'starts_at' => '2026-11-02 07:00:00', 'ends_at' => '2026-11-02 16:00:00']);
        $this->post('/appointments', $this->data(['doctor_id' => $other->id]))->assertSessionHasErrors('patient_id');
    }

    public function test_inactive_non_doctor_unavailable_and_past_slots_are_rejected(): void
    {
        $this->actor();
        $this->post('/appointments', $this->data(['starts_at' => '2026-11-02T17:00', 'ends_at' => '2026-11-02T17:30']))->assertSessionHasErrors('starts_at');
        $this->post('/appointments', $this->data(['starts_at' => '2026-11-01T09:00', 'ends_at' => '2026-11-01T09:30']))->assertSessionHasErrors('starts_at');
        $this->doctor->update(['name' => 'Médecin Test']);
        $this->doctor->forceFill(['status' => 'suspended'])->save();
        $this->post('/appointments', $this->data())->assertSessionHasErrors('doctor_id');
        $other = User::factory()->create(['role' => 'cashier']);
        $this->post('/appointments', $this->data(['doctor_id' => $other->id]))->assertSessionHasErrors('doctor_id');
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_bad_intervals_are_rejected(): void
    {
        $this->actor();
        $this->post('/appointments', $this->data(['ends_at' => '2026-11-02T08:59']))->assertSessionHasErrors('ends_at');
        $this->post('/appointments', $this->data(['ends_at' => '2026-11-03T09:30']))->assertSessionHasErrors('ends_at');
        $this->post('/appointments', $this->data(['starts_at' => 'invalid']))->assertSessionHasErrors('starts_at');
    }

    public function test_move_excludes_self_and_rechecks_conflicts(): void
    {
        $this->actor();
        $appointment = $this->book();
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->patch('/appointments/'.$appointment->id, $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $appointment->fresh()->status);
        $other = $this->book(['starts_at' => '2026-11-02T10:00', 'ends_at' => '2026-11-02T10:30']);
        $this->patch('/appointments/'.$other->id, $this->data())->assertSessionHasErrors('starts_at');
        $this->assertSame('09:00', $other->fresh()->starts_at->format('H:i'));
    }

    public function test_cancellation_releases_slot_and_terminal_state_cannot_be_reopened_or_moved(): void
    {
        $this->actor();
        $appointment = $this->book();
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'cancelled'])->assertSessionHasNoErrors();
        $this->book();
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'confirmed'])->assertSessionHasErrors('status');
        $this->patch('/appointments/'.$appointment->id, $this->data())->assertSessionHasErrors('starts_at');
    }

    public function test_doctor_can_follow_own_appointment_but_not_another_or_cancel(): void
    {
        $this->actor();
        $appointment = $this->book();
        $this->actor('doctor');
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'arrived'])->assertSessionHasErrors('status');
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'arrived'])->assertSessionHasNoErrors();
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $appointment->fresh()->status);
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'cancelled'])->assertForbidden();
        $other = User::factory()->create(['role' => 'doctor']);
        $this->actingAs($other)->patch('/appointments/'.$appointment->id.'/status', ['status' => 'arrived'])->assertForbidden();
    }

    public function test_absent_status_requires_start_time_and_cannot_skip_to_completed(): void
    {
        $this->actor();
        $appointment = $this->book();
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'completed'])->assertSessionHasErrors('status');
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'absent'])->assertSessionHasErrors('status');
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'absent'])->assertSessionHasNoErrors();
    }

    public function test_doctor_agenda_is_scoped_even_with_forged_doctor_filter(): void
    {
        $this->actor();
        $this->book();
        $other = User::factory()->create(['role' => 'doctor']);
        Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $other->id, 'starts_at' => '2026-11-02 10:00:00', 'ends_at' => '2026-11-02 11:00:00', 'status' => 'scheduled']);
        $this->actor('doctor');
        $this->get('/appointments?date=2026-11-02&doctor_id='.$other->id)->assertInertia(fn (Assert $page) => $page->has('appointments.data', 1)->where('appointments.data.0.doctor_id', $this->doctor->id)->where('calendar.0.count', 1)->has('doctors', 1));
    }

    public function test_permissions_protect_all_appointment_endpoints(): void
    {
        $this->get('/appointments')->assertRedirect('/login');
        $this->actor();
        $appointment = $this->book();
        foreach (['cashier', 'staff', 'doctor'] as $role) {
            $this->actor($role);
            $this->post('/appointments', $this->data())->assertForbidden();
            $this->patch('/appointments/'.$appointment->id, $this->data())->assertForbidden();
            $this->get('/appointments/patients?search=Alice')->assertForbidden();
            if ($role !== 'doctor') {
                $this->get('/appointments')->assertForbidden();
                $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'cancelled'])->assertForbidden();
            }
        }
    }

    public function test_patient_picker_is_limited_and_does_not_expose_contact_details(): void
    {
        $this->actor('secretary');
        $this->getJson('/appointments/patients?search=Alice')->assertOk()->assertJsonCount(1)->assertJsonMissingPath('0.email')->assertJsonMissingPath('0.search_text');
        $this->getJson('/appointments/patients?search=MC-'.$this->patient->id)->assertOk()->assertJsonPath('0.id', $this->patient->id);
    }

    public function test_filter_and_timezone_midnight_boundaries(): void
    {
        $this->actor();
        $appointment = $this->book();
        $this->get('/appointments?date=2026-11-02&status=confirmed')->assertInertia(fn (Assert $page) => $page->has('appointments.data', 0));
        $this->get('/appointments?date=2026-11-02&doctor_id='.$this->doctor->id)->assertInertia(fn (Assert $page) => $page->has('appointments.data', 1));
        Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-01 23:30:00', 'ends_at' => '2026-11-01 23:45:00', 'status' => 'completed']);
        $this->get('/appointments?date=2026-11-02')->assertInertia(fn (Assert $page) => $page->has('appointments.data', 2));
        $this->get('/appointments?date=2026-11-01')->assertInertia(fn (Assert $page) => $page->has('appointments.data', 0));
    }

    public function test_invalid_and_ambiguous_daylight_saving_times_are_rejected(): void
    {
        $this->actor();
        config(['clinic.timezone' => 'Europe/Paris']);
        $this->post('/appointments', $this->data(['starts_at' => '2027-03-28T02:15', 'ends_at' => '2027-03-28T03:30']))->assertSessionHasErrors('starts_at');
        $this->post('/appointments', $this->data(['starts_at' => '2027-10-31T02:15', 'ends_at' => '2027-10-31T03:30']))->assertSessionHasErrors('starts_at');
    }

    public function test_availability_is_owned_non_overlapping_and_protected_while_booked(): void
    {
        $this->actor('doctor');
        $this->post('/availabilities', ['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-02T16:00', 'ends_at' => '2026-11-02T18:00'])->assertSessionHasErrors('starts_at');
        $this->post('/availabilities', ['doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-03T08:00', 'ends_at' => '2026-11-03T17:00'])->assertSessionHasNoErrors();
        $other = User::factory()->create(['role' => 'doctor']);
        $this->post('/availabilities', ['doctor_id' => $other->id, 'starts_at' => '2026-11-03T08:00', 'ends_at' => '2026-11-03T17:00'])->assertForbidden();
        $this->actor();
        $appointment = $this->book();
        $slot = DoctorAvailability::where('doctor_id', $this->doctor->id)->orderBy('id')->firstOrFail();
        $this->delete('/availabilities/'.$slot->id)->assertSessionHasErrors('availability');
        $this->patch('/appointments/'.$appointment->id.'/status', ['status' => 'cancelled'])->assertSessionHasNoErrors();
        $this->delete('/availabilities/'.$slot->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('doctor_availabilities', ['id' => $slot->id]);
    }

    public function test_patient_details_do_not_expose_another_doctors_appointments(): void
    {
        $this->actor();
        $this->book();
        $other = User::factory()->create(['role' => 'doctor']);
        Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $other->id, 'starts_at' => '2026-11-02 10:00:00', 'ends_at' => '2026-11-02 11:00:00', 'status' => 'scheduled']);
        $this->actor('doctor');
        $this->get('/patients/'.$this->patient->id)->assertInertia(fn (Assert $page) => $page->has('appointments', 1)->where('appointments.0.doctor', $this->doctor->name));
    }

    public function test_paginated_agenda_keeps_filters_and_calendar_preview_is_bounded(): void
    {
        $this->actor();
        for ($i = 0; $i < 21; $i++) {
            Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'starts_at' => '2026-11-02 10:00:00', 'ends_at' => '2026-11-02 11:00:00', 'status' => 'cancelled']);
        }
        $this->get('/appointments?date=2026-11-02&status=cancelled&doctor_id='.$this->doctor->id)->assertInertia(fn (Assert $page) => $page->has('appointments.data', 20)->where('appointments.total', 21)->has('calendar.0.items', 3)->where('calendar.0.count', 21)->where('appointments.next_page_url', fn ($url) => str_contains($url, 'status=cancelled') && str_contains($url, 'doctor_id='.$this->doctor->id)));
    }

    public function test_other_doctor_and_secretary_cannot_remove_availability(): void
    {
        $slot = DoctorAvailability::firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'doctor']))->delete('/availabilities/'.$slot->id)->assertForbidden();
        $this->actor('secretary');
        $this->get('/availabilities')->assertForbidden();
        $this->delete('/availabilities/'.$slot->id)->assertForbidden();
    }

    public function test_doctor_with_an_agenda_cannot_delete_account(): void
    {
        $this->actor('doctor');
        $this->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertNotNull($this->doctor->fresh());
    }
}
