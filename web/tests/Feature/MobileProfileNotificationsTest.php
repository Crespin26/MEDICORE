<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentPush;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\NotificationPreference;
use App\Models\Patient;
use App\Models\PushDevice;
use App\Models\User;
use App\Services\AppointmentNotifications;
use App\Services\FirebasePush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class MobileProfileNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private ?string $credentials = null;

    protected function tearDown(): void
    {
        if ($this->credentials && is_file($this->credentials)) {
            unlink($this->credentials);
        }
        parent::tearDown();
    }

    private function member(string $role = 'doctor'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function loginAs(User $user): string
    {
        $token = $user->createToken('Appareil fictif', ['mobile'], now()->addHours(8))->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($token);

        return $token;
    }

    private function preferences(User $user, array $changes = []): NotificationPreference
    {
        return NotificationPreference::create(['user_id' => $user->id, ...NotificationPreference::defaults(), ...$changes]);
    }

    private function booking(User $doctor, array $changes = []): Appointment
    {
        $patient = Patient::firstOrCreate(['first_name' => 'Patient', 'last_name' => 'Confidentiel fictif', 'birth_date' => '1990-01-01']);

        return Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'starts_at' => now()->addMinutes(30), 'ends_at' => now()->addHour(), 'status' => 'scheduled', ...$changes]);
    }

    private function device(User $user): PushDevice
    {
        $token = $user->createToken('Push fictif', ['mobile'], now()->addHours(8));

        return PushDevice::create(['user_id' => $user->id, 'personal_access_token_id' => $token->accessToken->id, 'installation_id' => 'fictif-installation-12345', 'installation_hash' => hash('sha256', 'fictif-installation-12345'), 'platform' => 'android']);
    }

    public function test_profile_update_is_scoped_and_keeps_web_validation_and_token(): void
    {
        foreach (['admin', 'doctor', 'secretary', 'cashier'] as $role) {
            $user = $this->member($role);
            $token = $this->loginAs($user);
            $email = $role.'-updated@medicore.test';
            $this->patchJson('/api/v1/me', ['name' => 'Profil fictif', 'email' => $email, 'role' => 'admin', 'status' => 'suspended', 'id' => 999])
                ->assertOk()->assertJsonPath('data.name', 'Profil fictif')->assertJsonPath('data.role', $role)->assertJsonMissingPath('data.password');
            $this->assertSame($role, $user->fresh()->role);
            $this->assertSame('active', $user->fresh()->status);
            $this->assertNull($user->fresh()->email_verified_at);
            Auth::forgetGuards();
            $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $email);
        }
        $other = $this->member();
        $this->patchJson('/api/v1/me', ['name' => 'Fictif', 'email' => $other->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson('/api/v1/me', ['name' => '', 'email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_new_routes_require_real_bearer_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/notification-preferences')->assertUnauthorized();
        $this->patchJson('/api/v1/me', [])->assertUnauthorized();
        $this->putJson('/api/v1/push-device', [])->assertUnauthorized();
    }

    public function test_preferences_default_to_opt_out_and_reject_forbidden_or_unavailable_channels(): void
    {
        $user = $this->member();
        $this->loginAs($user);
        $this->getJson('/api/v1/notification-preferences')->assertOk()->assertJsonPath('data.appointment_updates', false)->assertJsonPath('data.push_available', false);
        $this->assertDatabaseCount('notification_preferences', 0);
        $data = [...NotificationPreference::defaults(), 'appointment_updates' => true, 'appointment_reminders' => true, 'reminder_minutes' => 30];
        $this->putJson('/api/v1/notification-preferences', $data)->assertOk()->assertJsonPath('data.reminder_minutes', 30);
        $this->putJson('/api/v1/notification-preferences', [...$data, 'reminder_minutes' => 7])->assertUnprocessable();
        $this->putJson('/api/v1/notification-preferences', [...$data, 'push_enabled' => true])->assertUnprocessable();
        $this->loginAs($this->member('cashier'));
        $this->putJson('/api/v1/notification-preferences', $data)->assertForbidden();
    }

    public function test_changes_are_opt_in_role_scoped_and_do_not_expose_patient_data(): void
    {
        $doctor = $this->member();
        $other = $this->member();
        $secretary = $this->member('secretary');
        $this->preferences($doctor, ['appointment_updates' => true]);
        $this->preferences($other, ['appointment_updates' => true]);
        $this->preferences($secretary, ['appointment_updates' => true]);
        $appointment = $this->booking($doctor);
        $this->assertDatabaseCount('appointment_notifications', 2);
        $this->loginAs($doctor);
        $response = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 1)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('Confidentiel', $response->getContent());
        $response->assertJsonMissingPath('notifications.data.0.appointment_id');
        $id = $response->json('notifications.data.0.id');
        $this->patchJson('/api/v1/notifications/'.$id.'/read')->assertNoContent();
        $readAt = AppointmentNotification::findOrFail($id)->read_at;
        $this->patchJson('/api/v1/notifications/'.$id.'/read')->assertNoContent();
        $this->assertEquals($readAt, AppointmentNotification::findOrFail($id)->read_at);
        $this->loginAs($other);
        $this->patchJson('/api/v1/notifications/'.$id.'/read')->assertNotFound();
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread_count', 0);
        $appointment->update(['doctor_id' => $other->id]);
        $this->loginAs($doctor);
        $this->getJson('/api/v1/notifications')->assertJsonCount(0, 'notifications.data');
    }

    public function test_rolled_back_booking_has_no_notification_and_opt_out_stops_new_events(): void
    {
        $doctor = $this->member();
        $preference = $this->preferences($doctor, ['appointment_updates' => true]);
        try {
            DB::transaction(function () use ($doctor) {
                $this->booking($doctor);
                throw new RuntimeException('Rollback de recette');
            });
        } catch (RuntimeException) {
        }
        $this->assertDatabaseCount('appointment_notifications', 0);
        $preference->update(['appointment_updates' => false]);
        $this->booking($doctor);
        $this->assertDatabaseCount('appointment_notifications', 0);
    }

    public function test_reminders_are_idempotent_and_ignore_past_cancelled_and_other_agendas(): void
    {
        $this->freezeTime();
        $doctor = $this->member();
        $this->preferences($doctor, ['appointment_reminders' => true]);
        $appointment = $this->booking($doctor);
        $this->booking($doctor, ['starts_at' => now()->addHours(2)]);
        $this->booking($doctor, ['status' => 'cancelled']);
        $this->booking($doctor, ['starts_at' => now()->subMinute()]);
        $this->booking($this->member());
        $service = app(AppointmentNotifications::class);
        $this->assertSame(1, $service->remind());
        $this->assertSame(0, $service->remind());
        $appointment->update(['starts_at' => now()->addMinutes(40)]);
        $this->assertSame(1, $service->remind());
        $this->assertSame(0, $service->remind());
        $this->assertDatabaseCount('appointment_notifications', 2);
    }

    public function test_device_registration_is_encrypted_and_removed_with_session_or_global_opt_out(): void
    {
        config(['mobile_notifications.push_enabled' => true]);
        $user = $this->member();
        $this->preferences($user, ['push_enabled' => true]);
        $this->loginAs($user);
        $data = ['installation_id' => 'fictif-installation-12345', 'platform' => 'android'];
        $this->putJson('/api/v1/push-device', $data)->assertNoContent();
        $device = PushDevice::firstOrFail();
        $this->assertNotSame($data['installation_id'], $device->getRawOriginal('installation_id'));
        $this->assertSame($data['installation_id'], $device->installation_id);
        $this->assertArrayNotHasKey('installation_id', $device->toArray());
        $this->deleteJson('/api/v1/session')->assertNoContent();
        $this->assertDatabaseCount('push_devices', 0);
        $this->loginAs($user);
        $this->putJson('/api/v1/push-device', $data)->assertNoContent();
        $this->putJson('/api/v1/notification-preferences', NotificationPreference::defaults())->assertOk();
        $this->assertDatabaseCount('push_devices', 0);
    }

    public function test_outbox_is_dispatched_only_when_enabled_and_job_rechecks_current_access(): void
    {
        Queue::fake();
        config(['mobile_notifications.push_enabled' => true]);
        $doctor = $this->member();
        $this->preferences($doctor, ['appointment_updates' => true, 'push_enabled' => true]);
        $this->device($doctor);
        $appointment = $this->booking($doctor);
        $notification = AppointmentNotification::firstOrFail();
        $this->assertSame(1, app(AppointmentNotifications::class)->dispatchPushes());
        Queue::assertPushed(SendAppointmentPush::class, fn ($job) => $job->notificationId === $notification->id);
        $push = $this->mock(FirebasePush::class);
        $push->shouldReceive('send')->once();
        (new SendAppointmentPush($notification->id))->handle($push);
        $this->assertNotNull($notification->fresh()->push_dispatched_at);
        $appointment->update(['status' => 'cancelled']);
        $second = AppointmentNotification::where('id', '!=', $notification->id)->firstOrFail();
        $appointment->update(['doctor_id' => $this->member()->id]);
        (new SendAppointmentPush($second->id))->handle($push);
        config(['mobile_notifications.push_enabled' => false]);
        $this->assertSame(0, app(AppointmentNotifications::class)->dispatchPushes());
    }

    public function test_expired_device_tokens_and_outdated_reminders_are_not_delivered(): void
    {
        config(['mobile_notifications.push_enabled' => true]);
        $doctor = $this->member();
        $this->preferences($doctor, ['appointment_reminders' => true, 'push_enabled' => true]);
        $device = $this->device($doctor);
        $appointment = $this->booking($doctor);
        app(AppointmentNotifications::class)->remind();
        $notification = AppointmentNotification::firstOrFail();
        $push = $this->mock(FirebasePush::class);
        $push->shouldNotReceive('send');
        DB::table('personal_access_tokens')->where('id', $device->personal_access_token_id)->update(['expires_at' => now()->subMinute()]);
        (new SendAppointmentPush($notification->id))->handle($push);
        $this->assertDatabaseCount('push_devices', 0);
        $notification->update(['push_dispatched_at' => null]);
        $appointment->update(['starts_at' => now()->addDays(2)]);
        (new SendAppointmentPush($notification->id))->handle($push);
    }

    private function prepareFirebaseFixture(): void
    {
        $this->credentials = tempnam(sys_get_temp_dir(), 'medicore-fcm-test-');
        chmod($this->credentials, 0600);
        $credential = ['type' => 'service_account', 'project_id' => 'medicore-fixture', 'client_email' => 'fixture@medicore.test', 'private_key' => 'fictitious-private-key', 'private_key_id' => 'fixture'];
        file_put_contents($this->credentials, json_encode($credential));
        config(['mobile_notifications.firebase_credentials' => $this->credentials]);
        $cacheKey = 'medicore-fcm:'.hash('sha256', $credential['client_email'].'|'.$credential['project_id'].'|'.$credential['private_key_id']);
        Cache::put($cacheKey, Crypt::encryptString('fictitious-oauth'), 60);
        Http::preventStrayRequests();
    }

    public function test_firebase_payload_is_generic_and_contains_only_the_notification_id(): void
    {
        $this->prepareFirebaseFixture();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['name' => 'fixture'], 200)]);
        $doctor = $this->member();
        $this->preferences($doctor, ['appointment_updates' => true]);
        $this->booking($doctor);
        $notification = AppointmentNotification::firstOrFail();
        app(FirebasePush::class)->send($this->device($doctor), $notification);
        Http::assertSent(fn ($request) => $request['message']['fid'] === 'fictif-installation-12345'
            && $request['message']['data'] === ['notification_id' => $notification->id]
            && $request['message']['notification'] === AppointmentNotification::message()
            && ! str_contains($request->body(), 'Confidentiel'));
    }

    public function test_unregistered_firebase_installation_is_deleted_and_transport_errors_are_redacted(): void
    {
        $this->prepareFirebaseFixture();
        $doctor = $this->member();
        $this->preferences($doctor, ['appointment_updates' => true]);
        $this->booking($doctor);
        $notification = AppointmentNotification::firstOrFail();
        Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 404)]);
        app(FirebasePush::class)->send($this->device($doctor), $notification);
        $this->assertDatabaseCount('push_devices', 0);
        Http::fake(['fcm.googleapis.com/*' => Http::response(['error' => 'provider secret'], 500)]);
        try {
            app(FirebasePush::class)->send($this->device($doctor), $notification);
            $this->fail('Le transport doit signaler son échec.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('provider secret', $e->getMessage());
            $this->assertStringNotContainsString('fictitious-oauth', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }
}
