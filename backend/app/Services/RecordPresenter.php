<?php

namespace App\Services;

use App\Models\Allergy;
use App\Models\Condition;
use App\Models\DiagnosticReport;
use App\Models\DocumentReference;
use App\Models\Encounter;
use App\Models\Medication;
use App\Models\Observation;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Model;

/** Maps access categories to record models and presents each item with provenance and freshness. */
class RecordPresenter
{
    public const MODELS = [
        'encounters' => ['encounter', Encounter::class, ['encounter_class', 'ended_at', 'reason']],
        'conditions' => ['condition', Condition::class, ['clinical_status', 'onset_date', 'resolved_date']],
        'medications' => ['medication', Medication::class, ['dose_text', 'route', 'frequency_text', 'medication_status', 'start_date', 'end_date']],
        'allergies' => ['allergy', Allergy::class, ['reaction_text', 'severity', 'allergy_status']],
        'observations' => ['observation', Observation::class, ['category', 'value_number', 'value_text', 'unit', 'reference_low', 'reference_high', 'interpretation']],
        'diagnostic_reports' => ['diagnostic_report', DiagnosticReport::class, ['report_type', 'conclusion', 'report_status']],
        'documents' => ['document_reference', DocumentReference::class, ['document_type', 'title', 'mime_type', 'size_bytes', 'scan_status']],
    ];

    public static function present(string $category, Model $m): array
    {
        [$type, , $fields] = self::MODELS[$category];
        $threshold = (int) config('medipass.stale_after_days');
        $open = $m->corrections()->where('correction_status', 'open')->exists();

        $item = [
            'id' => $m->uuid,
            'type' => $type,
            'category' => $category,
            'source' => [
                'facility' => $m->sourceFacility?->name,
                'system' => $m->source_system,
                'identifier' => $m->source_identifier,
                'author' => $m->author ? trim($m->author->given_name.' '.$m->author->family_name) : null,
            ],
            'origin' => $m->verification_status === 'patient_entered' ? 'patient_entered' : ($m->source_system ? 'source_system' : 'staff_entered'),
            'clinical_date' => $m->clinical_date,
            'ingested_at' => $m->ingested_at,
            'verification_status' => $m->verification_status,
            'record_status' => $m->record_status,
            'stale' => $m->clinical_date->lt(now()->subDays($threshold)),
            'correction_open' => $open,
        ];
        // Original wording, units and local codes are preserved exactly as supplied.
        foreach (['local_code', 'local_display', 'code_system', 'code'] as $f) {
            if (array_key_exists($f, $m->getAttributes())) {
                $item[$f] = $m->{$f};
            }
        }
        foreach ($fields as $f) {
            $item[$f === 'category' ? 'observation_category' : $f] = $m->{$f}; // `category` is the access category
        }

        return $item;
    }

    public static function timeline(Patient $patient, array $categories): array
    {
        $items = [];
        foreach ($categories as $category) {
            [, $class] = self::MODELS[$category];
            $query = $class::where('patient_id', $patient->id)->with(['sourceFacility', 'author']);
            if ($category === 'documents') {
                $query->where('scan_status', 'clean');
            }
            foreach ($query->get() as $m) {
                $items[] = self::present($category, $m);
            }
        }
        usort($items, fn ($a, $b) => $b['clinical_date'] <=> $a['clinical_date']);

        return $items;
    }

    public static function completeness(array $items): array
    {
        return [
            'complete' => false,
            'note' => 'This timeline only includes records shared with you from connected sources and may be partial.',
            'sources' => array_values(array_unique(array_filter(array_map(fn ($i) => $i['source']['facility'], $items)))),
            'stale_after_days' => (int) config('medipass.stale_after_days'),
        ];
    }
}
