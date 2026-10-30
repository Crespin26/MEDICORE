<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Patient;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardReport
{
    /** Construit les indicateurs autorisés sur une période à fin exclusive ; ne mélange jamais les devises. */
    public function build(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return DB::transaction(function () use ($user, $start, $end) {
            $from = $start->utc();
            $to = $end->utc();
            $today = CarbonImmutable::now(config('clinic.timezone'))->startOfDay();
            $report = ['patientCount' => $user->can('patients.view') ? Patient::count() : null, 'filters' => ['from' => $start->toDateString(), 'to' => $end->subDay()->toDateString()], 'today' => $today->toDateString(), 'timezone' => config('clinic.timezone'), 'asOf' => CarbonImmutable::now('UTC')->toIso8601String()];
            if ($user->can('patients.view')) {
                $report['newPatients'] = Patient::where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
            }
            if ($user->can('appointments.view')) {
                $base = Appointment::query()->when($user->role === 'doctor', fn ($q) => $q->where('doctor_id', $user->id));
                $report['appointments'] = ['statuses' => (clone $base)->where('starts_at', '>=', $from)->where('starts_at', '<', $to)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
                    'todayCount' => (clone $base)->where('starts_at', '>=', $today->utc())->where('starts_at', '<', $today->addDay()->utc())->count(),
                    'todayItems' => (clone $base)->with(['patient:id,first_name,last_name', 'doctor:id,name'])->where('starts_at', '>=', $today->utc())->where('starts_at', '<', $today->addDay()->utc())->orderBy('starts_at')->orderBy('id')->limit(8)->get()->map(fn ($a) => ['id' => $a->id, 'patient' => $a->patient->last_name.' '.$a->patient->first_name, 'doctor' => $a->doctor->name, 'time' => $a->starts_at->setTimezone(config('clinic.timezone'))->format('H:i'), 'status' => $a->status]),
                ];
            }
            if ($user->can('consultations.view')) {
                $report['consultations'] = Consultation::where('doctor_id', $user->id)->where('consulted_at', '>=', $from)->where('consulted_at', '<', $to)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
            }
            if ($user->can('billing.view')) {
                $currencies = Invoice::distinct()->orderBy('currency')->pluck('currency');
                $finance = [];
                foreach ($currencies as $currency) {
                    $payments = InvoicePayment::whereHas('invoice', fn ($q) => $q->where('currency', $currency));
                    $gross = (int) (clone $payments)->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->sum('amount_minor');
                    $voided = (int) (clone $payments)->where('voided_at', '>=', $from)->where('voided_at', '<', $to)->sum('amount_minor');
                    $balance = (int) Invoice::where('currency', $currency)->whereIn('status', ['issued', 'partially_paid'])->sum(DB::raw('total_minor - paid_minor'));
                    $finance[] = ['currency' => $currency, 'gross_minor' => (string) $gross, 'voided_minor' => (string) $voided, 'net_minor' => (string) ($gross - $voided), 'outstanding_minor' => (string) $balance,
                        'gross' => Money::format($gross, $currency), 'voided' => Money::format($voided, $currency), 'net' => Money::format($gross - $voided, $currency), 'outstanding' => Money::format($balance, $currency)];
                }
                $report['finance'] = $finance;
            }

            return $report;
        });
    }

    /** Transforme les agrégats autorisés en lignes CSV sans identité de patient. */
    public function csvRows(array $report): array
    {
        $rows = [['metric', 'count', 'currency', 'amount_minor', 'date_from', 'date_to', 'as_of_utc']];
        $append = function ($metric, $count = '', $currency = '', $amount = '') use (&$rows, $report) {
            $rows[] = [$metric, (string) $count, $currency, $amount, $report['filters']['from'], $report['filters']['to'], $report['asOf']];
        };
        if ($report['patientCount'] !== null) {
            $append('patients_total_current', $report['patientCount']);
            $append('patients_created_period', $report['newPatients']);
        }
        foreach ($report['appointments']['statuses'] ?? [] as $status => $count) {
            $append('appointments_'.$status, $count);
        }
        foreach ($report['finance'] ?? [] as $row) {
            foreach (['gross_minor', 'voided_minor', 'net_minor', 'outstanding_minor'] as $field) {
                $append($field, currency: $row['currency'], amount: $row[$field]);
            }
        }

        return $rows;
    }
}
