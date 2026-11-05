<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Services\AppointmentNotifications;

class AppointmentObserver
{
    /** Enregistre la création dans la transaction de réservation, sans envoi externe. */
    public function created(Appointment $appointment): void
    {
        app(AppointmentNotifications::class)->changed($appointment, 'created');
    }

    /** Ignore les écritures techniques et trace seulement les changements de planning pertinents. */
    public function updated(Appointment $appointment): void
    {
        if ($appointment->wasChanged(['starts_at', 'ends_at', 'doctor_id', 'patient_id', 'status'])) {
            app(AppointmentNotifications::class)->changed($appointment, $appointment->status === 'cancelled' ? 'cancelled' : 'updated');
        }
    }
}
