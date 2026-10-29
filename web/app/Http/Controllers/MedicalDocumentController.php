<?php

namespace App\Http\Controllers;

use App\Models\MedicalDocument;
use App\Models\Patient;
use App\Models\User;
use App\Services\AppointmentScheduler;
use App\Services\MedicalAudit;
use finfo;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

class MedicalDocumentController extends Controller
{
    public function store(Request $request, Patient $patient, AppointmentScheduler $scheduler)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png']]);
        $file = $request->file('file');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $extensions = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']];
        if (! in_array(strtolower($file->getClientOriginalExtension()), $extensions[$mime] ?? [], true)) {
            throw ValidationException::withMessages(['file' => 'Le contenu et l’extension du fichier doivent correspondre (PDF, JPEG ou PNG).']);
        }
        $content = file_get_contents($file->getRealPath());
        abort_if($content === false, 422, 'Fichier illisible.');
        $path = Str::uuid().'.enc';
        try {
            Storage::disk('medical')->put($path, Crypt::encryptString($content));
            $scheduler->transaction(function () use ($request, $patient, $scheduler, $data, $file, $mime, $extensions, $content, $path) {
                $scheduler->lockDoctors([$request->user()->id]);
                abort_unless(User::findOrFail($request->user()->id)->hasPermission('medical-record.write'), 403);
                Patient::whereKey($patient->id)->lockForUpdate()->firstOrFail()->assertActive();
                $document = MedicalDocument::create([
                    'patient_id' => $patient->id, 'doctor_id' => $request->user()->id, 'title' => $data['title'],
                    'original_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'path' => $path, 'mime_type' => $mime, 'extension' => $extensions[$mime][0], 'size' => strlen($content), 'sha256' => hash('sha256', $content),
                ]);
                MedicalAudit::record($request->user(), $patient->id, 'document.uploaded', 'document', $document->id);
            });
        } catch (Throwable $error) {
            Storage::disk('medical')->delete($path);
            throw $error;
        }

        return to_route('medical-record.show', $patient)->with('status', 'Document ajouté au stockage privé.');
    }

    public function download(Request $request, Patient $patient, MedicalDocument $document)
    {
        Gate::authorize('view', $document);
        abort_unless($document->patient_id === $patient->id, 404);
        abort_unless(Storage::disk('medical')->exists($document->path), 404);
        try {
            $content = Crypt::decryptString(Storage::disk('medical')->get($document->path));
        } catch (DecryptException $error) {
            abort(409, 'L’intégrité du document ne peut pas être vérifiée.');
        }
        abort_unless(hash_equals($document->sha256, hash('sha256', $content)), 409, 'L’intégrité du document ne peut pas être vérifiée.');
        MedicalAudit::record($request->user(), $patient->id, 'document.downloaded', 'document', $document->id);

        return response($content)->withHeaders([
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', 'medical-document-'.$document->id.'.'.$document->extension),
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
