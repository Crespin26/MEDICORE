<?php

namespace App\Http\Controllers;

use App\Models\ClinicalShare;
use App\Models\MedicalAccessEvent;
use App\Models\MedicalDocument;
use App\Models\MedicalRecordEntry;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Services\ClinicalAccess;
use App\Services\MedicalAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MedicalRecordController extends Controller
{
    public function show(Request $request, Patient $patient)
    {
        $data = $request->validate(['kind' => ['nullable', Rule::in(array_keys(MedicalRecordEntry::KINDS))]]);
        $authors = ClinicalAccess::authors($request->user(), $patient->id);
        $entries = MedicalRecordEntry::where('patient_id', $patient->id)->whereIn('doctor_id', $authors)
            ->when($data['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))->orderByDesc('updated_at')->orderByDesc('id')->paginate(15)->withQueryString();
        $documents = MedicalDocument::where('patient_id', $patient->id)->whereIn('doctor_id', $authors)->orderByDesc('id')->paginate(10, ['*'], 'documents_page')->withQueryString();
        $timeline = DB::table('consultations')->select('id', DB::raw("'consultation' as type"), 'consulted_at as event_at')
            ->where('patient_id', $patient->id)->whereIn('doctor_id', $authors)
            ->unionAll(DB::table('medical_record_entries')->select('id', DB::raw("'entry' as type"), 'updated_at as event_at')->where('patient_id', $patient->id)->whereIn('doctor_id', $authors))
            ->unionAll(DB::table('medical_documents')->select('id', DB::raw("'document' as type"), 'created_at as event_at')->where('patient_id', $patient->id)->whereIn('doctor_id', $authors))
            ->orderByDesc('event_at')->orderBy('type')->orderByDesc('id')->paginate(20, ['*'], 'timeline_page')->withQueryString();
        MedicalAudit::record($request->user(), $patient->id, 'record.view', 'patient', $patient->id);

        return Inertia::render('medical-record/show', [
            'patient' => $patient->only('id', 'first_name', 'last_name'), 'entries' => $entries, 'documents' => $documents, 'timeline' => $timeline,
            'kinds' => MedicalRecordEntry::KINDS, 'kind' => $data['kind'] ?? '', 'timezone' => config('clinic.timezone'),
            'sharingDoctors' => User::where('role', 'doctor')->where('status', 'active')->whereKeyNot($request->user()->id)->get(['id', 'name']),
            'outgoingShares' => ClinicalShare::where('patient_id', $patient->id)->where('owner_id', $request->user()->id)->whereNull('revoked_at')->get(['id', 'viewer_id', 'created_at']),
            'incomingAuthors' => User::whereIn('id', array_diff($authors, [$request->user()->id]))->get(['id', 'name']),
            'archived' => $patient->archived_at !== null,
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(array_keys(MedicalRecordEntry::KINDS))],
            'label' => ['required', 'string', 'max:500'], 'details' => ['nullable', 'string', 'max:10000'],
            'occurred_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now(config('clinic.timezone'))->toDateString()],
            'status' => ['required', 'in:active,resolved'],
            'version' => [$request->isMethod('patch') ? 'required' : 'nullable', 'integer', 'min:1'],
            'correction_reason' => [$request->isMethod('patch') ? 'required' : 'nullable', 'string', 'max:1000'],
        ]);
    }

    public function store(Request $request, Patient $patient, AppointmentScheduler $scheduler)
    {
        $data = $this->validated($request);
        $entry = $scheduler->transaction(function () use ($request, $patient, $scheduler, $data) {
            $scheduler->lockDoctors([$request->user()->id]);
            abort_unless(User::findOrFail($request->user()->id)->hasPermission('medical-record.write'), 403);
            Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail()->assertActive();
            $entry = new MedicalRecordEntry($data);
            $entry->patient_id = $patient->id;
            $entry->doctor_id = $request->user()->id;
            $entry->version = 1;
            $entry->save();
            $this->revision($entry, $request, null);

            return $entry;
        });

        return to_route('medical-entries.show', [$patient, $entry])->with('status', 'Information médicale enregistrée.');
    }

    public function entry(Request $request, Patient $patient, MedicalRecordEntry $entry)
    {
        Gate::authorize('view', $entry);
        abort_unless($entry->patient_id === $patient->id, 404);
        $revisions = $entry->revisions()->orderByDesc('version')->paginate(10)->withQueryString();
        MedicalAudit::record($request->user(), $patient->id, 'entry.view', 'entry', $entry->id, $entry->version);

        return Inertia::render('medical-record/entry', ['patient' => $patient->only('id', 'first_name', 'last_name'), 'entry' => $entry, 'canUpdate' => $request->user()->can('update', $entry), 'revisions' => $revisions, 'kinds' => MedicalRecordEntry::KINDS, 'timezone' => config('clinic.timezone')]);
    }

    public function update(Request $request, Patient $patient, MedicalRecordEntry $entry, AppointmentScheduler $scheduler)
    {
        Gate::authorize('update', $entry);
        abort_unless($entry->patient_id === $patient->id, 404);
        $data = $this->validated($request);
        $scheduler->transaction(function () use ($request, $entry, $scheduler, $data) {
            $scheduler->lockDoctors([$request->user()->id]);
            $actor = User::findOrFail($request->user()->id);
            $entry = MedicalRecordEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $entry);
            if ($entry->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'Cette information a été modifiée. Rechargez la page avant de poursuivre.']);
            }
            $entry->fill($data);
            $entry->version++;
            $entry->save();
            $this->revision($entry, $request, $data['correction_reason']);
        });

        return to_route('medical-entries.show', [$patient, $entry])->with('status', 'Information corrigée et version conservée.');
    }

    private function revision(MedicalRecordEntry $entry, Request $request, ?string $reason): void
    {
        $entry->revisions()->create([
            'author_id' => $request->user()->id, 'author_name' => $request->user()->name, 'version' => $entry->version,
            'correction_reason' => $reason, 'snapshot' => $entry->only('kind', 'label', 'details', 'occurred_on', 'status'), 'created_at' => now(),
        ]);
        MedicalAudit::record($request->user(), $entry->patient_id, $reason ? 'entry.updated' : 'entry.created', 'entry', $entry->id, $entry->version);
    }

    public function audit(Request $request, Patient $patient)
    {
        MedicalAudit::record($request->user(), $patient->id, 'audit.view', 'patient', $patient->id);

        return Inertia::render('medical-record/audit', ['patient' => $patient->only('id', 'first_name', 'last_name'),
            'events' => MedicalAccessEvent::where('patient_id', $patient->id)->where('actor_id', $request->user()->id)->orderByDesc('id')->paginate(30)->withQueryString(), 'timezone' => config('clinic.timezone')]);
    }
}
