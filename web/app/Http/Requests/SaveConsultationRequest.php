<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveConsultationRequest extends FormRequest
{
    /** Exige la permission de saisir une consultation ; les droits d’auteur sont vérifiés séparément. */
    public function authorize(): bool
    {
        return $this->user()->can('consultations.write') && (! $this->route('consultation') || $this->user()->can('update', $this->route('consultation')));
    }

    /** Décrit les champs cliniques autorisés et les contraintes de correction et de version. */
    public function rules(): array
    {
        return [
            'consulted_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'max:500'],
            'observations' => ['nullable', 'string', 'max:20000'],
            'report' => [Rule::requiredIf($this->input('action') === 'validate'), 'nullable', 'string', 'max:30000'],
            'action' => ['required', Rule::in(['draft', 'validate'])],
            'version' => [$this->isMethod('patch') ? 'required' : 'nullable', 'integer', 'min:1'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'correction_reason' => ['nullable', 'string', 'max:1000'],
            'vitals' => ['nullable', 'array:temperature,pulse,systolic,diastolic,oxygen_saturation,weight,height'],
            'vitals.temperature' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'vitals.pulse' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'vitals.systolic' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'vitals.diastolic' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'vitals.oxygen_saturation' => ['nullable', 'numeric', 'between:0,100'],
            'vitals.weight' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'vitals.height' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ];
    }
}
