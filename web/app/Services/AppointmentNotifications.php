<?php

namespace App\Services;

use App\Jobs\SendAppointmentPush;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Str;

class AppointmentNotifications
{
    /** Conserve les événements dans la transaction métier ; aucun transport externe n’est appelé. */
    public function changed(Appointment $appointment, string $kind): void
    {
        $event = (string) Str::uuid();
        NotificationPreference::with('user')->where('appointment_updates', true)->each(function ($preference) use ($appointment, $kind, $event) {
            if ($this->eligible($preference->user, $appointment)) {
                $this->record($appointment, $preference, $kind, $event.'|'.$preference->user_id);
            }
        });
    }

    /** Un médecin ne reçoit que son agenda ; les administrateurs/secrétaires ont leur périmètre global. */
    public function eligible(User $user, Appointment $appointment): bool
    {
        return $user->hasPermission('appointments.view') && ($user->role !== 'doctor' || $appointment->doctor_id === $user->id);
    }

    /** Produit au plus un rappel par destinataire, horaire et délai, même après un redémarrage du scheduler. */
    public function remind(): int
    {
        return app(AppointmentScheduler::class)->transaction(function () {
            $scheduler = app(AppointmentScheduler::class);
            $scheduler->lockDoctors(User::where('role', 'doctor')->pluck('id')->all());
            $created = 0;
            NotificationPreference::with('user')->where('appointment_reminders', true)->each(function ($preference) use (&$created) {
                if (! $preference->user->hasPermission('appointments.view')) {
                    return;
                }
                Appointment::whereIn('status', ['scheduled', 'confirmed'])->where('starts_at', '>', now())
                    ->where('starts_at', '<=', now()->addMinutes($preference->reminder_minutes))
                    ->when($preference->user->role === 'doctor', fn ($q) => $q->where('doctor_id', $preference->user_id))
                    ->each(function ($appointment) use ($preference, &$created) {
                        $key = implode('|', ['reminder', $appointment->id, $appointment->starts_at->toIso8601String(), $preference->user_id, $preference->reminder_minutes]);
                        $created += (int) $this->record($appointment, $preference, 'reminder', $key);
                    });
            });

            return $created;
        });
    }

    /** L’outbox persistante évite de dépendre d’un envoi réseau pendant une réservation. */
    public function dispatchPushes(): int
    {
        if (! config('mobile_notifications.push_enabled')) {
            return 0;
        }
        $count = 0;
        AppointmentNotification::where('wants_push', true)->whereNull('push_dispatched_at')->whereNull('read_at')
            ->where('created_at', '>=', now()->subHour())->orderBy('created_at')->limit(100)->get()
            ->each(function ($notification) use (&$count) {
                SendAppointmentPush::dispatch($notification->id);
                $count++;
            });

        return $count;
    }

    /** Ne conserve ni nom patient, ni motif, ni contenu médical dans la boîte ou les jobs. */
    private function record(Appointment $appointment, NotificationPreference $preference, string $kind, string $key): bool
    {
        $notification = AppointmentNotification::firstOrCreate(['deduplication_key' => hash('sha256', $key)], [
            'id' => (string) Str::uuid(), 'user_id' => $preference->user_id, 'appointment_id' => $appointment->id,
            'kind' => $kind, 'created_at' => now(), 'appointment_starts_at' => $appointment->starts_at,
            'wants_push' => $preference->push_enabled && config('mobile_notifications.push_enabled'),
        ]);

        return $notification->wasRecentlyCreated;
    }
}
