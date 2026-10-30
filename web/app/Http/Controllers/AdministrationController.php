<?php

namespace App\Http\Controllers;

use App\Models\AdministrationExport;
use App\Models\Appointment;
use App\Models\ClinicSetting;
use App\Models\ClinicSettingEvent;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Services\DashboardReport;
use App\Support\ClinicHours;
use App\Support\Money;
use App\Support\ReportingPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdministrationController extends Controller
{
    /** Affiche les paramètres de la clinique et leur historique paginé. */
    public function settings()
    {
        $setting = ClinicSetting::findOrFail(1);

        return Inertia::render('administration/settings', ['setting' => [...$setting->toArray(), 'currency' => $setting->currency ?? config('clinic.currency')], 'timezone' => config('clinic.timezone'), 'events' => ClinicSettingEvent::orderByDesc('id')->paginate(10)->withQueryString()]);
    }

    /** Versionne les paramètres sous verrou et refuse des horaires excluant des rendez-vous actifs. */
    public function update(Request $request, AppointmentScheduler $scheduler)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:500'],
            'currency' => ['required', Rule::in(array_keys(Money::SCALES))], 'enforce_hours' => ['required', 'boolean'],
            'opening_hours' => ['required', 'array:1,2,3,4,5,6,7', 'size:7'], 'opening_hours.*' => ['array:enabled,opens,closes'],
            'opening_hours.*.enabled' => ['required', 'boolean'], 'opening_hours.*.opens' => ['nullable', 'date_format:H:i'], 'opening_hours.*.closes' => ['nullable', 'date_format:H:i'],
            'version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:1000'],
        ]);
        $hours = [];
        if ($data['enforce_hours']) {
            foreach ($data['opening_hours'] as $day => $rule) {
                if ($rule['enabled'] && (empty($rule['opens']) || empty($rule['closes']) || $rule['closes'] <= $rule['opens'])) {
                    throw ValidationException::withMessages(['opening_hours' => 'Chaque journée ouverte doit avoir une fin après son début.']);
                }
                $hours[$day] = ['enabled' => (bool) $rule['enabled'], 'opens' => ($rule['opens'] ?? null) ?: '08:00', 'closes' => ($rule['closes'] ?? null) ?: '17:00'];
            }
            if (! collect($hours)->contains(fn ($rule) => $rule['enabled'])) {
                throw ValidationException::withMessages(['opening_hours' => 'Activez au moins une journée.']);
            }
        }
        $scheduler->transaction(function () use ($request, $scheduler, $data, $hours) {
            $scheduler->lockDoctors(User::where('role', 'doctor')->pluck('id')->all());
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasPermission('administration.manage'), 403);
            $setting = ClinicSetting::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($setting->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Les paramètres ont changé. Rechargez la page.']);
            }
            foreach (Appointment::whereIn('status', Appointment::OPEN)->where('ends_at', '>', now())->get(['starts_at', 'ends_at']) as $appointment) {
                if (! ClinicHours::fits($appointment->starts_at, $appointment->ends_at, $hours)) {
                    throw ValidationException::withMessages(['opening_hours' => 'Ces horaires excluraient des rendez-vous actifs. Déplacez-les ou annulez-les avant de poursuivre.']);
                }
            }
            $setting->fill(array_intersect_key($data, array_flip(['name', 'phone', 'email', 'address', 'currency'])));
            $setting->opening_hours = $hours;
            $setting->version++;
            $setting->save();
            ClinicSettingEvent::create(['actor_id' => $actor->id, 'actor_name' => $actor->name, 'version' => $setting->version, 'reason' => $data['reason'], 'snapshot' => $setting->toArray(), 'created_at' => now()]);
        });

        return to_route('administration.settings')->with('status', 'Paramètres enregistrés. Les opérations existantes conservent leur devise.');
    }

    /** Produit un CSV agrégé et journalise son motif et son empreinte avant téléchargement. */
    public function export(Request $request, DashboardReport $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        [$start, $end] = ReportingPeriod::from($request);
        $rows = $service->csvRows($service->build($request->user(), $start, $end));
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ';', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        AdministrationExport::create(['actor_id' => $request->user()->id, 'actor_name' => $request->user()->name, 'type' => 'aggregate_statistics', 'date_from' => $start->toDateString(), 'date_to' => $end->subDay()->toDateString(), 'reason' => $data['reason'], 'row_count' => count($rows) - 1, 'sha256' => hash('sha256', $csv), 'created_at' => now()]);

        return response($csv)->withHeaders(['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="medicore-statistics.csv"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Affiche l’historique des exports administratifs sans contenu médical. */
    public function exports()
    {
        return Inertia::render('administration/exports', ['exports' => AdministrationExport::orderByDesc('id')->paginate(30), 'timezone' => config('clinic.timezone')]);
    }
}
