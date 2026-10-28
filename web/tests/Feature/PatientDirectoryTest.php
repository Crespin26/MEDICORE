<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PatientDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function data(array $extra = []): array
    {
        return array_replace(['first_name' => 'Alice', 'last_name' => 'Exemple', 'birth_date' => '1990-01-02', 'phone' => '00112233', 'email' => 'alice@example.test', 'address' => 'Adresse fictive'], $extra);
    }

    private function login(string $role = 'admin'): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
    }

    public function test_search_by_name_full_name_phone_email_and_reference(): void
    {
        $patient = Patient::create($this->data());
        Patient::create($this->data(['first_name' => 'Bob', 'phone' => null, 'email' => 'bob@example.test']));
        $this->login();
        foreach (['alice', 'ALICE EXEMPLE', 'Exemple Alice', '00112233', 'alice@example.test', 'MC-'.$patient->id] as $search) {
            $this->get('/patients?'.http_build_query(['search' => $search]))->assertInertia(fn (Assert $page) => $page->component('patients/index', false)->has('patients.data', 1)->where('patients.data.0.id', $patient->id));
        }
    }

    public function test_name_search_ignores_accents_and_letter_case(): void
    {
        Patient::create($this->data(['first_name' => 'Élodie', 'last_name' => 'Éxemple']));
        $this->login();
        $this->get('/patients?search=ELODIE%20EXEMPLE')->assertInertia(fn (Assert $page) => $page->has('patients.data', 1));
    }

    public function test_percent_and_underscore_are_literal_search_characters(): void
    {
        Patient::create($this->data());
        $this->login();
        foreach (['%', '_'] as $search) {
            $this->get('/patients?'.http_build_query(['search' => $search]))->assertInertia(fn (Assert $page) => $page->has('patients.data', 0));
        }
    }

    public function test_combined_filters_and_upper_birth_bound_without_lower_bound(): void
    {
        $patient = Patient::create($this->data());
        Patient::create($this->data(['first_name' => 'Bob', 'phone' => null, 'birth_date' => '2000-01-01']));
        $this->login();
        $this->get('/patients?birth_from=1980-01-01&birth_to=1995-01-01&contact=with_phone')->assertInertia(fn (Assert $page) => $page->has('patients.data', 1)->where('patients.data.0.id', $patient->id));
        $this->get('/patients?contact=without_phone')->assertInertia(fn (Assert $page) => $page->has('patients.data', 1)->where('patients.data.0.first_name', 'Bob'));
        $this->get('/patients?birth_from=&birth_to=1995-01-01')->assertInertia(fn (Assert $page) => $page->has('patients.data', 1));
        $this->get('/patients?birth_from=2001-01-01&birth_to=2000-01-01')->assertSessionHasErrors('birth_to');
    }

    public function test_filters_survive_pagination_and_sort_is_stable(): void
    {
        for ($index = 1; $index <= 21; $index++) {
            Patient::create($this->data(['first_name' => 'Patient '.$index]));
        }
        $this->login();
        $this->get('/patients?search=Exemple&contact=with_phone')->assertInertia(fn (Assert $page) => $page->has('patients.data', 20)->where('patients.total', 21)->where('patients.next_page_url', fn ($url) => str_contains($url, 'search=Exemple') && str_contains($url, 'contact=with_phone')));
        $this->get('/patients?search=Exemple&contact=with_phone&page=2')->assertInertia(fn (Assert $page) => $page->has('patients.data', 1));
    }

    public function test_details_are_available_only_to_authorized_roles(): void
    {
        $patient = Patient::create($this->data());
        $this->get('/patients/'.$patient->id)->assertRedirect('/login');
        foreach (['admin', 'doctor', 'secretary'] as $role) {
            $this->login($role);
            $this->get('/patients/'.$patient->id)->assertInertia(fn (Assert $page) => $page->component('patients/show', false)->where('patient.email', 'alice@example.test')->missing('patient.identity_key')->missing('patient.search_text'));
        }
        foreach (['cashier', 'staff'] as $role) {
            $this->login($role);
            $this->get('/patients/'.$patient->id)->assertForbidden();
        }
        $this->login();
        $this->get('/patients/999999')->assertNotFound();
    }

    public function test_new_fields_are_saved_and_validated(): void
    {
        $this->login('secretary');
        $this->post('/patients', $this->data())->assertRedirect('/patients');
        $this->assertDatabaseHas('patients', ['email' => 'alice@example.test', 'address' => 'Adresse fictive']);
        $this->post('/patients', $this->data(['email' => 'invalid', 'address' => str_repeat('x', 501)]))->assertSessionHasErrors(['email', 'address']);
    }

    public function test_identity_duplicate_is_rejected_after_case_and_space_normalization(): void
    {
        Patient::create($this->data());
        $this->login();
        $this->post('/patients', $this->data(['first_name' => '  ALICE ', 'last_name' => ' exemple ']))->assertSessionHasErrors('first_name');
        $this->assertDatabaseCount('patients', 1);
        $this->post('/patients', $this->data(['birth_date' => '1991-01-02']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('patients', 2);
    }

    public function test_patient_can_be_updated_without_self_duplicate_but_not_to_another_identity(): void
    {
        $alice = Patient::create($this->data());
        $bob = Patient::create($this->data(['first_name' => 'Bob']));
        $this->login();
        $this->from('/patients/'.$alice->id)->patch('/patients/'.$alice->id, $this->data(['address' => 'Nouvelle adresse fictive']))->assertRedirect('/patients/'.$alice->id)->assertSessionHasNoErrors();
        $this->patch('/patients/'.$bob->id, $this->data())->assertSessionHasErrors('first_name');
        $this->assertSame('Bob', $bob->fresh()->first_name);
    }

    public function test_database_rejects_a_concurrent_identity_duplicate(): void
    {
        Patient::create($this->data());
        $this->expectException(UniqueConstraintViolationException::class);
        Patient::create($this->data(['first_name' => ' ALICE ']));
    }

    public function test_legacy_identity_without_key_is_still_detected(): void
    {
        DB::table('patients')->insert($this->data());
        $this->login();
        $this->post('/patients', $this->data())->assertSessionHasErrors('first_name');
        $this->assertDatabaseCount('patients', 1);
    }

    public function test_doctor_cannot_change_contact_details_and_no_delete_route_exists(): void
    {
        $patient = Patient::create($this->data());
        $this->login('doctor');
        $this->patch('/patients/'.$patient->id, $this->data(['email' => 'other@example.test']))->assertForbidden();
        $this->login();
        $this->delete('/patients/'.$patient->id)->assertStatus(405);
        $this->assertNotNull($patient->fresh());
    }
}
