<?php

namespace App\Http\Controllers\Api;

use App\Models\AppointmentNotification;
use App\Models\NotificationPreference;
use App\Models\PushDevice;
use App\Models\User;
use App\Support\ClinicTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MobileNotificationController
{
    /** Expose les choix du compte, sans créer de préférence implicitement. */
    public function preferences(Request $request)
    {
        $preference = NotificationPreference::where('user_id', $request->user()->id)->first();

        return response()->json(['data' => [
            ...($preference?->only(array_keys(NotificationPreference::defaults())) ?? NotificationPreference::defaults()),
            'can_receive_appointments' => $request->user()->hasPermission('appointments.view'),
            'push_available' => (bool) config('mobile_notifications.push_enabled'),
        ]]);
    }

    /** Enregistre un choix explicite ; aucun rôle ni paramètre d’un autre compte n’est modifiable. */
    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'appointment_updates' => ['required', 'boolean'], 'appointment_reminders' => ['required', 'boolean'],
            'reminder_minutes' => ['required', 'integer', Rule::in([15, 30, 60, 120, 1440])], 'push_enabled' => ['required', 'boolean'],
        ]);
        abort_if(($data['appointment_updates'] || $data['appointment_reminders'] || $data['push_enabled']) && ! $request->user()->hasPermission('appointments.view'), 403);
        abort_if($data['push_enabled'] && ! config('mobile_notifications.push_enabled'), 422, 'Les notifications push ne sont pas encore configurées.');
        DB::transaction(function () use ($request, $data) {
            $actor = $this->lockedActor($request);
            abort_if(($data['appointment_updates'] || $data['appointment_reminders'] || $data['push_enabled']) && ! $actor->hasPermission('appointments.view'), 403);
            NotificationPreference::updateOrCreate(['user_id' => $request->user()->id], $data);
            if (! $data['push_enabled']) {
                PushDevice::where('user_id', $request->user()->id)->delete();
            }
        });

        return $this->preferences($request);
    }

    /** Pagine uniquement les notifications dont le destinataire et les droits actuels correspondent. */
    public function index(Request $request)
    {
        $query = AppointmentNotification::visibleTo($request->user())->with('appointment:id,doctor_id,starts_at');
        $unread = (clone $query)->whereNull('read_at')->count();

        return response()->json(['notifications' => $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->through(fn ($n) => [
            'id' => $n->id, 'kind' => $n->kind, ...AppointmentNotification::message(),
            'appointment_date' => $n->appointment->starts_at->setTimezone(config('clinic.timezone'))->toDateString(),
            'appointment_time' => ClinicTime::local($n->appointment->starts_at),
            'created_at' => $n->created_at->toIso8601String(), 'read_at' => $n->read_at?->toIso8601String(),
        ]), 'unread_count' => $unread]);
    }

    /** La lecture est idempotente et ne permet pas de modifier une notification d’un autre membre. */
    public function read(Request $request, string $notification)
    {
        $record = AppointmentNotification::visibleTo($request->user())->findOrFail($notification);
        $record->update(['read_at' => $record->read_at ?? now()]);

        return response()->noContent();
    }

    /** Lie le identifiant Firebase chiffré à la session courante ; la révocation de session le supprimera. */
    public function device(Request $request)
    {
        abort_unless($request->user()->hasPermission('appointments.view'), 403);
        abort_unless(config('mobile_notifications.push_enabled'), 422, 'Transport push indisponible.');
        $data = $request->validate(['installation_id' => ['required', 'string', 'min:20', 'max:4096'], 'platform' => ['required', Rule::in(['android', 'ios'])]]);
        DB::transaction(function () use ($request, $data) {
            $actor = $this->lockedActor($request);
            abort_unless($actor->hasPermission('appointments.view'), 403);
            // Même verrou que l’opt-out : une inscription en attente ne peut pas le contourner.
            abort_unless(NotificationPreference::where('user_id', $actor->id)->where('push_enabled', true)->exists(), 403);
            $session = $request->user()->currentAccessToken()->id;
            $hash = hash('sha256', $data['installation_id']);
            PushDevice::where('installation_hash', $hash)->where('personal_access_token_id', '!=', $session)->delete();
            PushDevice::updateOrCreate(['personal_access_token_id' => $session], ['user_id' => $request->user()->id, 'installation_hash' => $hash, ...$data]);
            // Borne le coût d’un job et les sessions susceptibles de recevoir un push.
            $keep = PushDevice::where('user_id', $request->user()->id)->orderByDesc('updated_at')->orderByDesc('id')->limit(5)->pluck('id');
            PushDevice::where('user_id', $request->user()->id)->whereNotIn('id', $keep)->delete();
        });

        return response()->noContent();
    }

    /** Supprime uniquement l’inscription push de la session de cet appareil. */
    public function forgetDevice(Request $request)
    {
        PushDevice::where('personal_access_token_id', $request->user()->currentAccessToken()->id)->delete();

        return response()->noContent();
    }

    /** Réserve le verrou SQLite avant lecture et recharge le compte actif pour les mutations de consentement. */
    private function lockedActor(Request $request): User
    {
        if (DB::getDriverName() === 'sqlite') {
            User::whereKey($request->user()->id)->update(['updated_at' => DB::raw('updated_at')]);
        }
        $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        abort_unless($actor->status === 'active', 401);
        $token = $actor->tokens()->whereKey($request->user()->currentAccessToken()->id)->lockForUpdate()->first();
        abort_unless($token && $token->can('mobile') && (! $token->expires_at || $token->expires_at->isFuture()), 401);

        return $actor;
    }
}
