<?php

namespace App\Jobs;

use App\Models\AppointmentNotification;
use App\Models\NotificationPreference;
use App\Models\PushDevice;
use App\Models\User;
use App\Services\FirebasePush;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Sanctum\PersonalAccessToken;

class SendAppointmentPush implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    public int $uniqueFor = 900;

    public function __construct(public string $notificationId) {}

    /** Une seule tâche active par notification ; aucun jeton n’est sérialisé dans la file. */
    public function uniqueId(): string
    {
        return $this->notificationId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    /** Revérifie droits, préférence, lecture et fraîcheur avant de joindre le fournisseur. */
    public function handle(FirebasePush $push): void
    {
        $notification = AppointmentNotification::find($this->notificationId);
        if (! $notification || $notification->push_dispatched_at) {
            return;
        }
        $user = User::find($notification->user_id);
        $preference = NotificationPreference::where('user_id', $notification->user_id)->first();
        $category = $notification->kind === 'reminder' ? 'appointment_reminders' : 'appointment_updates';
        $currentReminder = $notification->kind !== 'reminder' || (
            $notification->appointment && in_array($notification->appointment->status, ['scheduled', 'confirmed'], true)
            && $notification->appointment->starts_at->equalTo($notification->appointment_starts_at)
            && $notification->appointment->starts_at->isFuture()
        );
        $visible = $user && AppointmentNotification::visibleTo($user)->whereKey($notification->id)->exists();
        if (config('mobile_notifications.push_enabled') && $notification->wants_push && ! $notification->read_at
            && $notification->created_at->gte(now()->subHour()) && $visible && $currentReminder && $preference?->push_enabled && $preference->$category) {
            foreach (PushDevice::where('user_id', $user->id)->latest()->limit(5)->get() as $device) {
                // Expired/revoked personal access tokens cannot receive notifications.
                $token = PersonalAccessToken::find($device->personal_access_token_id);
                if (! $token || ! $token->can('mobile') || ($token->expires_at && $token->expires_at->isPast())) {
                    $device->delete();

                    continue;
                }
                $push->send($device, $notification);
            }
        }
        $notification->update(['push_dispatched_at' => now()]);
    }
}
