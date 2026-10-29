<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DoctorAvailability;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    public function withToken($value, $type = 'Bearer')
    {
        // A new HTTP request has fresh guards in production; reset cached test guards.
        Auth::forgetGuards();

        return parent::withToken($value, $type);
    }

    private function user(string $role = 'secretary'): User
    {
        return User::factory()->create(['role' => $role, 'password' => 'Mobile-Fictif-2026!']);
    }

    private function token(User $user): string
    {
        return $user->createToken('Recette mobile', ['mobile'], now()->addHours(8))->plainTextToken;
    }

    public function test_real_bearer_login_hashes_token_and_logout_revokes_only_current_device(): void
    {
        $user = $this->user();
        $other = $this->token($user);
        $response = $this->postJson('/api/v1/session', ['email' => $user->email, 'password' => 'Mobile-Fictif-2026!', 'device_name' => 'Téléphone fictif'])->assertCreated()->assertJsonPath('data.user.role', 'secretary')->assertJsonMissingPath('data.user.password')->assertHeader('Cache-Control', 'no-store, private');
        $token = $response->json('data.token');
        $this->assertNotSame($token, $user->tokens()->latest('id')->value('token'));
        $this->assertNotNull($user->tokens()->latest('id')->value('expires_at'));
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->withToken($token)->deleteJson('/api/v1/session')->assertNoContent();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken($other)->getJson('/api/v1/me')->assertOk();
    }

    public function test_malformed_login_fields_return_validation_errors_instead_of_server_errors(): void
    {
        $this->postJson('/api/v1/session', ['email' => ['unexpected'], 'password' => ['unexpected'], 'device_name' => []])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'device_name']);
    }

    public function test_missing_token_web_cookie_and_wrong_ability_cannot_access_mobile_routes(): void
    {
        $this->get('/api/v1/patients')->assertUnauthorized()->assertJsonStructure(['message'])->assertHeader('Cache-Control', 'no-store, private');
        $user = $this->user();
        $this->actingAs($user)->getJson('/api/v1/patients')->assertUnauthorized();
        $token = $user->createToken('Autre usage', ['other'])->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/patients')->assertUnauthorized();
    }

    public function test_expiry_suspension_password_and_role_changes_revoke_mobile_access(): void
    {
        foreach (['expired', 'suspended', 'password', 'role'] as $change) {
            $user = $this->user();
            $token = $this->token($user);
            match ($change) {
                'expired' => $user->tokens()->update(['expires_at' => now()->subMinute()]),
                'suspended' => $user->forceFill(['status' => 'suspended'])->save(),
                'password' => $user->update(['password' => Hash::make('Autre-mot-de-passe')]),
                'role' => $user->forceFill(['role' => 'cashier'])->save(),
            };
            $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        }
    }

    public function test_login_failures_are_generic_and_throttled_and_inactive_accounts_get_no_token(): void
    {
        $user = $this->user();
        $user->forceFill(['status' => 'suspended'])->save();
        $data = ['email' => $user->email, 'password' => 'Mobile-Fictif-2026!', 'device_name' => 'Fictif'];
        $this->postJson('/api/v1/session', $data)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/session', $data)->assertUnprocessable();
        }
        $this->postJson('/api/v1/session', $data)->assertTooManyRequests();
    }

    public function test_patient_permissions_pagination_validation_and_archive_match_the_web(): void
    {
        $patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Mobile', 'birth_date' => '1990-01-01']);
        $doctor = $this->user('doctor');
        $this->withToken($this->token($doctor))->getJson('/api/v1/patients?search=Alice')->assertOk()->assertJsonPath('data.0.id', $patient->id)->assertJsonPath('per_page', 20)->assertJsonMissingPath('data.0.identity_key')->assertJsonMissingPath('data.0.search_text');
        $this->postJson('/api/v1/patients', [])->assertForbidden();
        $this->withToken($this->token($this->user('cashier')))->getJson('/api/v1/patients')->assertForbidden();
        $this->withToken($this->token($this->user()))->postJson('/api/v1/patients', ['first_name' => 'Alice', 'last_name' => 'Mobile', 'birth_date' => '1990-01-01'])->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $response = $this->postJson('/api/v1/patients', ['first_name' => 'Autre', 'last_name' => 'Mobile', 'birth_date' => '1991-01-01'])->assertCreated();
        $this->patchJson('/api/v1/patients/'.$response->json('data.id'), ['first_name' => 'Autre', 'last_name' => 'Mobile', 'birth_date' => '1991-01-01', 'phone' => '12345678'])->assertOk()->assertJsonPath('data.phone', '12345678');
        $patient->archived_at = now();
        $patient->save();
        $this->getJson('/api/v1/patients?search=Alice')->assertJsonCount(0, 'data');
        $this->patchJson('/api/v1/patients/'.$patient->id, ['first_name' => 'Alice', 'last_name' => 'Mobile', 'birth_date' => '1990-01-01'])->assertUnprocessable()->assertJsonValidationErrors('patient_id');
    }

    public function test_doctor_agenda_is_scoped_and_booking_uses_shared_conflict_checks(): void
    {
        config(['clinic.timezone' => 'Africa/Porto-Novo']);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
        $doctor = $this->user('doctor');
        $other = $this->user('doctor');
        $patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Mobile', 'birth_date' => '1990-01-01']);
        DoctorAvailability::create(['doctor_id' => $doctor->id, 'starts_at' => '2026-11-03 08:00:00', 'ends_at' => '2026-11-03 17:00:00']);
        Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $other->id, 'starts_at' => '2026-11-03 12:00:00', 'ends_at' => '2026-11-03 12:30:00', 'status' => 'scheduled']);
        $this->withToken($this->token($doctor))->getJson('/api/v1/appointments?date=2026-11-03&doctor_id='.$other->id)->assertOk()->assertJsonCount(0, 'appointments.data');
        $this->postJson('/api/v1/appointments', [])->assertForbidden();
        $data = ['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'starts_at' => '2026-11-03T10:00', 'ends_at' => '2026-11-03T10:30'];
        $this->withToken($this->token($this->user()))->postJson('/api/v1/appointments', $data)->assertCreated()->assertJsonPath('data.status', 'scheduled');
        $this->postJson('/api/v1/appointments', $data)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->withToken($this->token($doctor))->getJson('/api/v1/appointments?date=2026-11-03')->assertJsonCount(1, 'appointments.data');
    }
}
