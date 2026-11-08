<?php

namespace Tests\Feature;

use App\Models\PersonnelEvent;
use App\Models\ProfessionalProfile;
use App\Models\StaffAbsence;
use App\Models\User;
use App\Services\PersonnelManager;
use App\Support\ClinicHours;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PersonnelAdministrationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'clinic.timezone' => 'Africa/Porto-Novo']);
        $this->travelTo(CarbonImmutable::parse('2030-01-07 06:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Administrateur fictif', 'email' => 'admin@medicore.test']);
        $this->doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Médecin fictif', 'email' => 'doctor@medicore.test']);
        $this->actingAs($this->admin);
    }

    private function profile(): array
    {
        return ['employee_number' => '0', 'job_title' => 'Fonction fictive', 'specialties' => [], 'version' => 0, 'reason' => 'Création fictive'];
    }

    public function test_zero_is_a_real_employee_number_in_personnel_search(): void
    {
        app(PersonnelManager::class)->profile($this->admin, $this->doctor->id, $this->profile());
        $this->get('/personnel?search=0')->assertInertia(fn (Assert $page) => $page
            ->has('staff.data', 1)->where('staff.data.0.id', $this->doctor->id));
    }

    public function test_zero_employee_number_collision_is_a_validation_error_under_the_service_lock(): void
    {
        $manager = app(PersonnelManager::class);
        $manager->profile($this->admin, $this->doctor->id, $this->profile());
        try {
            $manager->profile($this->admin, $this->admin->id, $this->profile());
            $this->fail('Le matricule zéro doit rester unique.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('employee_number', $error->errors());
        }
        $this->assertDatabaseCount('professional_profiles', 1);
        $this->assertDatabaseCount('personnel_events', 1);
        $this->assertSame($this->doctor->id, ProfessionalProfile::firstOrFail()->user_id);
    }

    public function test_clinic_closing_time_rejects_seconds_beyond_the_boundary(): void
    {
        $hours = ['1' => ['enabled' => true, 'opens' => '08:00', 'closes' => '17:00']];
        $start = CarbonImmutable::parse('2030-01-07 08:00:00', 'Africa/Porto-Novo');
        $closing = CarbonImmutable::parse('2030-01-07 17:00:00', 'Africa/Porto-Novo');
        $this->assertTrue(ClinicHours::fits($start->utc(), $closing->utc(), $hours));
        $this->assertFalse(ClinicHours::fits($start, $closing->addSecond(), $hours));
        $this->assertFalse(ClinicHours::fits($start, $closing->addMicrosecond(), $hours));
        $this->assertFalse(ClinicHours::fits($start->subSecond(), $closing, $hours));
    }

    public function test_dashboard_period_counts_calendar_days_across_both_clock_changes(): void
    {
        config(['clinic.timezone' => 'Europe/Paris']);
        $this->travelTo(CarbonImmutable::parse('2030-12-15 12:00:00', 'UTC'));
        foreach ([['2030-02-01', '2030-05-04'], ['2030-09-01', '2030-12-02']] as [$start, $end]) {
            $this->get('/dashboard?from='.$start.'&to='.$end)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', $start)->where('filters.to', $end)->where('timezone', 'Europe/Paris'));
        }
        $this->get('/dashboard?from=2030-09-01&to=2030-12-03')->assertSessionHasErrors('to');
    }

    public function test_failed_absence_cancellation_audit_keeps_the_absence_active(): void
    {
        $absence = app(PersonnelManager::class)->absence($this->admin, $this->doctor->id, [
            'starts_at' => '2030-01-08T08:00', 'ends_at' => '2030-01-08T17:00', 'category' => 'leave', 'reason' => 'Congé fictif',
        ]);
        PersonnelEvent::creating(function () {
            throw new \RuntimeException('Échec fictif de l’historique');
        });
        try {
            $this->post('/personnel/'.$this->doctor->id.'/absences/'.$absence->id.'/cancel', ['version' => 1, 'reason' => 'Annulation fictive'])->assertStatus(500);
            $this->assertSame('active', $absence->fresh()->status);
            $this->assertSame(1, $absence->fresh()->version);
            $this->assertNull($absence->fresh()->cancelled_at);
            $this->assertSame(1, StaffAbsence::count());
            $this->assertSame(1, PersonnelEvent::count());
        } finally {
            PersonnelEvent::flushEventListeners();
        }
    }
}
