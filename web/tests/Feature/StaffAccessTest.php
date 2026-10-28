<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin', string $status = 'active'): User
    {
        return User::factory()->create(compact('role', 'status'));
    }

    private function invite(): array
    {
        Notification::fake();
        $this->actingAs($this->account())->post('/staff', [
            'name' => 'Médecin Exemple', 'email' => 'doctor@example.test', 'role' => 'doctor',
        ])->assertRedirect()->assertSessionHas('status');
        $user = User::where('email', 'doctor@example.test')->firstOrFail();
        $notification = Notification::sent($user, StaffInvitation::class)->first();
        $this->assertNotNull($notification);

        return [$user, $notification->url];
    }

    public function test_permissions_for_each_role_and_legacy_staff(): void
    {
        $patient = Patient::create(['first_name' => 'Patient', 'last_name' => 'Exemple', 'birth_date' => '1990-01-01']);
        foreach (['admin', 'doctor', 'secretary', 'cashier', 'staff'] as $role) {
            $this->actingAs($this->account($role));
            $this->get('/dashboard')->assertStatus($role === 'staff' ? 403 : 200);
            $this->get('/staff')->assertStatus($role === 'admin' ? 200 : 403);
            $this->get('/patients')->assertStatus(in_array($role, ['admin', 'doctor', 'secretary']) ? 200 : 403);
            $data = ['first_name' => 'Fictif', 'last_name' => 'Exemple', 'birth_date' => '1991-01-01'];
            $this->post('/patients', $data)->assertStatus(in_array($role, ['admin', 'secretary']) ? 302 : 403);
            $this->patch('/patients/'.$patient->id, $data)->assertStatus(in_array($role, ['admin', 'secretary']) ? 302 : 403);
        }
    }

    public function test_non_admin_cannot_invite_change_role_suspend_or_resend(): void
    {
        $target = $this->account('secretary');
        foreach (['doctor', 'secretary', 'cashier', 'staff'] as $role) {
            $this->actingAs($this->account($role));
            $this->post('/staff', ['name' => 'Intrus', 'email' => 'intrus@example.test', 'role' => 'admin'])->assertForbidden();
            $this->patch('/staff/'.$target->id, ['role' => 'admin', 'status' => 'suspended'])->assertForbidden();
            $this->post('/staff/'.$target->id.'/invitation')->assertForbidden();
        }
        $this->assertSame('secretary', $target->fresh()->role);
        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_guest_cannot_manage_staff(): void
    {
        $this->get('/staff')->assertRedirect('/login');
        $this->post('/staff')->assertRedirect('/login');
        $this->patch('/staff/1')->assertRedirect('/login');
        $this->post('/staff/1/invitation')->assertRedirect('/login');
    }

    public function test_invitation_activates_once_with_a_hashed_password(): void
    {
        [$user, $url] = $this->invite();
        $this->assertSame('pending', $user->status);
        $token = basename(parse_url($url, PHP_URL_PATH));
        $this->assertSame(hash('sha256', $token), $user->invitation_token);
        $this->assertArrayNotHasKey('invitation_token', $user->toArray());
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk();
        $this->post($url, ['password' => 'valid-long-password', 'password_confirmation' => 'valid-long-password'])->assertRedirect('/login');
        $this->assertSame('active', $user->fresh()->status);
        $this->assertTrue(Hash::check('valid-long-password', $user->fresh()->password));
        $this->assertNull($user->fresh()->invitation_token);
        $this->get($url)->assertGone();
        $this->post($url, ['password' => 'another-long-password', 'password_confirmation' => 'another-long-password'])->assertGone();
        $this->post('/login', ['email' => $user->email, 'password' => 'valid-long-password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_expired_and_replaced_invitations_are_rejected(): void
    {
        [$user, $url] = $this->invite();
        $this->post('/staff/'.$user->id.'/invitation')->assertRedirect();
        $newUrl = Notification::sent($user, StaffInvitation::class)->last()->url;
        $this->assertNotSame($url, $newUrl);
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertGone();
        $this->get('/invitations/invalid')->assertGone();
        $this->travel(49)->hours();
        $this->get($newUrl)->assertGone();
        $this->post($newUrl, ['password' => 'valid-long-password', 'password_confirmation' => 'valid-long-password'])->assertGone();
        $this->assertSame('pending', $user->fresh()->status);
    }

    public function test_invitation_requires_strong_confirmed_password(): void
    {
        [$user, $url] = $this->invite();
        $this->app['auth']->forgetGuards();
        $this->post($url, ['password' => 'short', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertSame('pending', $user->fresh()->status);
    }

    public function test_pending_and_suspended_accounts_cannot_log_in_or_reset_password(): void
    {
        Notification::fake();
        foreach (['pending', 'suspended'] as $status) {
            $user = $this->account('doctor', $status);
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
            $this->assertGuest();
            $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
            Notification::assertNotSentTo($user, ResetPassword::class);
            $token = Password::createToken($user);
            $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'valid-long-password', 'password_confirmation' => 'valid-long-password'])->assertSessionHasErrors('email');
            $this->assertTrue(Hash::check('password', $user->fresh()->password));
        }
    }

    public function test_suspended_existing_session_is_logged_out_even_from_settings(): void
    {
        $user = $this->account('doctor');
        $this->actingAs($user);
        $user->status = 'suspended';
        $user->save();
        $this->get('/settings/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_can_suspend_and_reactivate_but_cannot_change_self(): void
    {
        $admin = $this->account();
        $user = $this->account('doctor');
        $this->actingAs($admin);
        $this->patch('/staff/'.$admin->id, ['role' => 'cashier', 'status' => 'suspended'])->assertForbidden();
        $this->patch('/staff/'.$user->id, ['role' => 'secretary', 'status' => 'suspended'])->assertRedirect();
        $this->assertSame('suspended', $user->fresh()->status);
        $this->patch('/staff/'.$user->id, ['role' => 'secretary', 'status' => 'active'])->assertRedirect();
        $this->assertSame('active', $user->fresh()->status);
        $this->assertSame('secretary', $user->fresh()->role);
        $this->post('/staff/'.$user->id.'/invitation')->assertStatus(409);
    }

    public function test_invalid_roles_duplicate_email_and_pending_manual_activation_are_rejected(): void
    {
        [$user] = $this->invite();
        $this->post('/staff', ['name' => 'Test', 'email' => $user->email, 'role' => 'invalid'])->assertSessionHasErrors(['email', 'role']);
        $this->patch('/staff/'.$user->id, ['role' => 'doctor', 'status' => 'active'])->assertStatus(409);
        $this->assertSame('pending', $user->fresh()->status);
    }

    public function test_admin_cannot_delete_self_and_profile_cannot_escalate_permissions(): void
    {
        $admin = $this->account();
        $this->actingAs($admin)->delete('/settings/profile', ['password' => 'password'])->assertSessionHasErrors('password');
        $this->assertNotNull($admin->fresh());
        $user = $this->account('cashier');
        $this->actingAs($user)->patch('/settings/profile', ['name' => 'Test', 'email' => $user->email, 'role' => 'admin', 'status' => 'active'])->assertRedirect();
        $this->assertSame('cashier', $user->fresh()->role);
    }

    public function test_cashier_dashboard_never_contains_patient_count_or_permissions(): void
    {
        $this->actingAs($this->account('cashier'))->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('dashboard', false)->where('patientCount', null)->where('auth.permissions', ['dashboard.view']));
    }

    public function test_invitation_is_rendered_and_sent_through_actual_local_mail_transport(): void
    {
        config(['mail.default' => 'array']);
        $this->actingAs($this->account())->post('/staff', ['name' => 'Personnel Test', 'email' => 'staff@example.test', 'role' => 'secretary'])->assertRedirect();
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame('Votre invitation MediCore', $email->getSubject());
        $this->assertSame('staff@example.test', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('/invitations/', $email->getHtmlBody());
        $this->assertStringContainsString('48 heures', $email->getHtmlBody());
    }

    public function test_suspension_revokes_persistent_sessions_and_remember_token(): void
    {
        $user = $this->account('doctor');
        $user->forceFill(['remember_token' => 'old-remember-token'])->save();
        DB::table('sessions')->insert([
            'id' => 'test-staff-session', 'user_id' => $user->id,
            'payload' => base64_encode(serialize([])), 'last_activity' => time(),
        ]);
        $this->actingAs($this->account())->patch('/staff/'.$user->id, ['role' => 'doctor', 'status' => 'suspended'])->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['id' => 'test-staff-session']);
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
    }

    public function test_password_reset_email_uses_local_transport_for_active_account(): void
    {
        config(['mail.default' => 'array']);
        $user = $this->account('doctor');
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect()->assertSessionHas('status');
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame($user->email, $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('/reset-password/', $email->getHtmlBody());
    }

    public function test_login_attempts_are_limited(): void
    {
        $user = $this->account('doctor');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_transport_failure_keeps_account_available_for_resending(): void
    {
        config(['mail.default' => 'invalid-transport']);
        $this->actingAs($this->account())->post('/staff', ['name' => 'Test', 'email' => 'retry@example.test', 'role' => 'secretary'])->assertRedirect()->assertSessionHas('status', fn ($status) => str_contains($status, 'échoué'));
        $this->assertDatabaseHas('users', ['email' => 'retry@example.test', 'status' => 'pending']);
    }
}
