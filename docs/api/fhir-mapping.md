# FHIR mapping (working default)

FHIR R4 (4.0.1). No implementation guide or national profile is asserted: none has been approved. **Terminology is not translated**: local codes and wording are stored and exported exactly as supplied; `code`/`system` are only filled when the source supplied them.

## Import (partner to MediPass)
| FHIR resource | MediPass record | Notes |
|---|---|---|
| Observation | observations | numeric `valueQuantity` or `valueString`; category laboratory or vital_sign |
| Condition | conditions | clinicalStatus active/resolved, otherwise unknown |
| AllergyIntolerance | allergies | |
| MedicationStatement | medications | dosage text kept as supplied |
| Encounter | encounters | class AMB, IMP or EMER |
| DiagnosticReport | diagnostic_reports | |

Required per resource: `id`, `meta.lastUpdated`, and a patient identifier. Anything else yields an item error code (`missing_id`, `missing_last_updated`, `missing_patient_identifier`, `unsupported_resource`, `malformed_resource`).

## Export (MediPass to FHIR)
`GET /api/v1/patient/fhir` and `GET /api/v1/provider/patients/{id}/fhir` return a `searchset` Bundle (`application/fhir+json`). Each category is gated by the same access decision and audit as the timeline; provider requests without consent get the same 403.

Exported: Patient (id only, no demographics), Encounter, Condition, Observation, MedicationStatement, AllergyIntolerance, DiagnosticReport, DocumentReference (metadata of clean documents only) and one Provenance per resource (source facility, ingest time). `meta.lastUpdated` is the ingest time; `meta.source` the source system.

## Unsupported in v1
Referral (ServiceRequest), Consent, AuditEvent, Practitioner/Organization resources, attachments content, terminology binding, FHIR search parameters, subscriptions and write-back to partners.

## Field authority
The source system is authoritative for records it created; staff-entered and patient-entered records are never overwritten by partners. Stale updates are skipped by `source_updated_at`.