<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PatientTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function payload(): array
    {
        return ['first_name' => 'Alice', 'last_name' => 'Exemple', 'birth_date' => '1990-01-01', 'phone' => '00000000'];
    }

    public function test_guests_cannot_access_patients(): void
    {
        $this->get('/patients')->assertRedirect('/login');
        $this->post('/patients', $this->payload())->assertRedirect('/login');
        $this->patch('/patients/1', $this->payload())->assertRedirect('/login');
    }

    public function test_staff_cannot_read_create_or_update_patients(): void
    {
        $patient = Patient::create($this->payload());
        $this->actingAs(User::factory()->create());
        $this->get('/patients')->assertForbidden();
        $this->get('/dashboard')->assertForbidden();
        $this->post('/patients', $this->payload())->assertForbidden();
        $this->patch('/patients/'.$patient->id, $this->payload())->assertForbidden();
        $this->assertDatabaseCount('patients', 1);
    }

    public function test_admin_can_create_list_and_update_patient(): void
    {
        $this->actingAs($this->admin());
        $this->post('/patients', $this->payload() + ['role' => 'admin'])->assertRedirect('/patients');
        $patient = Patient::firstOrFail();
        $this->get('/patients')->assertOk();
        $this->patch('/patients/'.$patient->id, array_replace($this->payload(), ['last_name' => 'Fictif']))->assertRedirect('/patients');
        $this->assertDatabaseHas('patients', ['id' => $patient->id, 'last_name' => 'Fictif']);
        $this->get('/dashboard')->assertOk();
    }

    public function test_invalid_patient_is_not_saved(): void
    {
        $this->actingAs($this->admin())->post('/patients', ['first_name' => '', 'birth_date' => '2999-01-01'])->assertSessionHasErrors(['first_name', 'last_name', 'birth_date']);
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'Intrus', 'email' => 'intrus@example.test', 'password' => 'password'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_command_creates_an_admin_without_exposing_password(): void
    {
        $this->artisan('medicore:create-admin')->expectsQuestion('Nom', 'Admin Fictif')->expectsQuestion('Adresse e-mail', 'admin@example.test')->expectsQuestion('Mot de passe (12 caractères minimum)', 'a-long-test-password')->expectsOutput('Administrateur créé.')->assertSuccessful();
        $user = User::firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('a-long-test-password', $user->password));
    }
}
