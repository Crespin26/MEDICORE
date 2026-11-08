<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Models\User;
use App\Services\PasswordUpdater;
use App\Support\ProductionConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function sessions(User $user): void
    {
        foreach (['first', 'second'] as $device) {
            DB::table('sessions')->insert(['id' => $device.'-'.$user->id, 'user_id' => $user->id, 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        }
    }

    public function test_password_change_revokes_sessions_tokens_remember_cookie_and_reset_links(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);
        $other = User::factory()->create(['role' => 'doctor']);
        $this->sessions($user);
        $this->sessions($other);
        $remember = $user->remember_token;
        $token = Password::createToken($user);
        $user->createToken('Téléphone fictif', ['mobile']);
        $this->actingAs($user)->put('/settings/password', ['current_password' => 'password', 'password' => 'Nouveau-secret-fictif!', 'password_confirmation' => 'Nouveau-secret-fictif!'])->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(2, DB::table('sessions')->where('user_id', $other->id)->count());
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotSame($remember, $user->fresh()->remember_token);
        $this->assertFalse(Password::tokenExists($user->fresh(), $token));
    }

    public function test_password_reset_revokes_all_existing_web_and_mobile_sessions(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);
        $this->sessions($user);
        $user->createToken('Téléphone fictif', ['mobile']);
        $remember = $user->remember_token;
        $token = Password::createToken($user);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'Nouveau-secret-fictif!', 'password_confirmation' => 'Nouveau-secret-fictif!'])->assertRedirect('/login');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotSame($remember, $user->fresh()->remember_token);
        $this->assertTrue(Hash::check('Nouveau-secret-fictif!', $user->fresh()->password));
    }

    public function test_rollout_revocation_requires_maintenance_and_preserves_accounts(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);
        $this->sessions($user);
        $user->createToken('Téléphone fictif', ['mobile']);
        Password::createToken($user);
        $this->artisan('medicore:revoke-sessions')->assertFailed();
        $this->assertSame(2, DB::table('sessions')->count());
        Artisan::call('down');
        try {
            $this->artisan('medicore:revoke-sessions')->assertSuccessful();
            $this->assertDatabaseCount('sessions', 0);
            $this->assertDatabaseCount('personal_access_tokens', 0);
            $this->assertDatabaseCount('password_reset_tokens', 0);
            $this->assertNull($user->fresh()->remember_token);
            $this->assertSame('doctor', $user->fresh()->role);
            $this->assertTrue(Hash::check('password', $user->fresh()->password));
        } finally {
            Artisan::call('up');
        }
    }

    public function test_an_old_password_cannot_overwrite_a_concurrent_password_change(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);
        $old = $user->fresh();
        $user->update(['password' => 'Premier-secret-fictif!']);
        try {
            app(PasswordUpdater::class)->change($old, 'password', 'Deuxieme-secret-fictif!');
            $this->fail('Le mot de passe courant doit être revérifié sous verrou.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('current_password', $error->errors());
        }
        $this->assertTrue(Hash::check('Premier-secret-fictif!', $user->fresh()->password));
    }

    public function test_a_stale_session_password_hash_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);
        $oldHash = $user->password;
        $user->update(['password' => 'Nouveau-secret-fictif!']);
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_email_change_invalidates_reset_links_before_the_address_can_be_reassigned(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);
        $oldEmail = $user->email;
        $token = Password::createToken($user);
        $user->update(['email' => 'nouvelle-adresse@medicore.test']);
        $newOwner = User::factory()->create(['role' => 'doctor', 'email' => $oldEmail]);
        $this->assertFalse(Password::tokenExists($newOwner, $token));
        $this->post('/reset-password', ['email' => $oldEmail, 'token' => $token, 'password' => 'Nouveau-secret-fictif!', 'password_confirmation' => 'Nouveau-secret-fictif!'])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $newOwner->fresh()->password));
    }

    public function test_a_reset_already_in_flight_cannot_overwrite_newer_credentials(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);
        $old = $user->fresh();
        $user->update(['password' => 'Premier-secret-fictif!']);
        try {
            app(PasswordUpdater::class)->reset($old, 'Deuxieme-secret-fictif!');
            $this->fail('Une ancienne vérification de lien ne peut pas écraser les nouveaux identifiants.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('email', $error->errors());
        }
        $this->assertTrue(Hash::check('Premier-secret-fictif!', $user->fresh()->password));
    }

    public function test_password_challenges_validate_types_and_limit_guessing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'doctor']));
        $this->post('/confirm-password', ['password' => ['malformed']])->assertSessionHasErrors('password');
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->post('/confirm-password', ['password' => 'incorrect'])->assertSessionHasErrors('password');
        }
        $this->post('/confirm-password', ['password' => 'password'])->assertTooManyRequests();
    }

    public function test_account_deletion_rejects_non_string_password_without_hashing_it(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);
        $this->actingAs($user)->delete('/settings/profile', ['password' => ['malformed']])->assertSessionHasErrors('password');
        $this->assertNotNull($user->fresh());
    }

    public function test_medical_uploads_are_rate_limited_before_processing_more_files(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::create(['first_name' => 'Alice', 'last_name' => 'Fictif', 'birth_date' => '1990-01-01']);
        $this->actingAs($user);
        for ($i = 0; $i < 10; $i++) {
            $this->post('/patients/'.$patient->id.'/medical-documents', [])->assertSessionHasErrors('file');
        }
        $this->post('/patients/'.$patient->id.'/medical-documents', [])->assertTooManyRequests();
        $this->assertDatabaseCount('medical_documents', 0);
    }

    public function test_inscription_rechecks_push_consent_after_acquiring_the_user_lock(): void
    {
        $this->pushInterleaving(false);
    }

    public function test_inscription_rechecks_session_revocation_after_acquiring_the_user_lock(): void
    {
        $this->pushInterleaving(true);
    }

    /** Simule un changement intervenu entre l’authentification et l’acquisition du verrou utilisateur. */
    private function pushInterleaving(bool $revoke): void
    {
        config(['mobile_notifications.push_enabled' => true]);
        $user = User::factory()->create(['role' => 'doctor']);
        $token = $user->createToken('Téléphone fictif', ['mobile'], now()->addHours(8));
        NotificationPreference::create(['user_id' => $user->id, ...NotificationPreference::defaults(), 'push_enabled' => true]);
        $reads = 0;
        $event = 'eloquent.retrieved: '.User::class;
        Event::listen($event, function (User $loaded) use ($user, $revoke, &$reads) {
            if ($loaded->id === $user->id && ++$reads === 2) {
                if ($revoke) {
                    $loaded->tokens()->delete();
                } else {
                    NotificationPreference::where('user_id', $user->id)->update(['push_enabled' => false]);
                }
            }
        });
        try {
            Auth::forgetGuards();
            $this->withToken($token->plainTextToken)->putJson('/api/v1/push-device', ['installation_id' => 'fictif-installation-12345', 'platform' => 'android'])->assertStatus($revoke ? 401 : 403);
            $this->assertGreaterThanOrEqual(2, $reads);
            $this->assertDatabaseCount('push_devices', 0);
        } finally {
            Event::forget($event);
        }
    }

    public function test_database_error_reporting_omits_sql_and_personal_data_in_production(): void
    {
        $this->app->instance('env', 'production');
        Log::shouldReceive('error')->once()->with('Erreur de base de données ; détails sensibles expurgés.', ['sql_state' => '0']);
        report(new QueryException('sqlite', 'insert into patients (first_name) values (?)', ['Identité fictive privée'], new \PDOException('Détails fictifs privés')));
    }

    public function test_smtp_errors_are_logged_without_their_sensitive_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Lien et identifiants fictifs privés'));
        Log::shouldReceive('warning')->once()->withArgs(fn ($message, $context) => $message === 'Échec du transport d’invitation.' && array_keys($context) === ['user_id', 'exception_class'] && $context['exception_class'] === \RuntimeException::class);
        $this->actingAs($admin)->post('/staff', ['name' => 'Médecin fictif', 'email' => 'doctor@medicore.test', 'role' => 'doctor'])->assertRedirect()->assertSessionHas('status');
    }

    public function test_normal_and_error_responses_block_framing_and_active_objects(): void
    {
        foreach (['/login' => 200, '/missing-page' => 404, '/api/v1/me' => 401] as $path => $status) {
            $this->get($path)->assertStatus($status)->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; object-src 'none'; base-uri 'self'");
        }
    }

    public function test_production_refuses_host_header_poisoning_before_generating_authentication_pages(): void
    {
        $this->app->instance('env', 'production');
        config(['app.url' => 'https://clinic.test', 'app.debug' => false]);
        $this->get('https://evil.test/login')->assertStatus(400)->assertHeader('X-Frame-Options', 'DENY');
        $this->get('https://clinic.test/login')->assertOk();
    }

    public function test_production_rejects_session_drivers_that_cannot_revoke_database_sessions(): void
    {
        foreach (['file', 'redis', 'cookie', 'array'] as $driver) {
            config(['session.driver' => $driver]);
            $this->assertContains('SESSION_DRIVER doit être database pour la révocation persistante des sessions de ce profil.', ProductionConfiguration::issues());
        }
    }

    public function test_production_command_detects_a_publicly_readable_environment_file(): void
    {
        $directory = sys_get_temp_dir().'/medicore-env-check-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents($directory.'/.env', '# Fixture fictive sans secret');
        chmod($directory.'/.env', 0644);
        $this->app->useEnvironmentPath($directory);
        try {
            $this->artisan('medicore:production-check')->expectsOutputToContain('Le fichier .env doit rester privé')->assertFailed();
        } finally {
            unlink($directory.'/.env');
            rmdir($directory);
        }
    }
}
