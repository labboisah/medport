# Phase 3 Domain Model Notes

**Working default (user-directed, 2026-10-29):** the minimum dataset proposed in `approvals/04_Clinical_Governance_Approval.docx` is used as the working default. It is **not** clinically approved; the clinical safety owner must still sign off before real data or care-affecting use.

## Implemented now (migrations, models, synthetic seeder)

| Area | Tables |
|---|---|
| Facility/practitioner registry | `facilities`, `practitioners`, `practitioner_roles` (verification status on each) |
| Patient identity | `patients`, `patient_identifiers`, `patient_match_candidates`, `patient_merge_events` |
| Consent and access | `consents` (always time-limited, recipient required), `access_grants` |
| Audit | `audit_events` (append-only, no payloads, no foreign keys) |

## Rules enforced

- Foreign keys are `restrictOnDelete`: nothing with history can be deleted; patients are merged by status, not removed.
- Uncertain matches live in `patient_match_candidates` as `pending_review`; no automatic merge exists. Merge/unmerge history is append-only.
- Unknown demographics stay `NULL`; identifiers are unique per `(system, value)`.
- Consent/grant "effective" = status active, not revoked, not expired (`Consent::effective()`, `AccessGrant::effective()`); the stored status flag alone is never trusted.
- `AuditEvent` and `PatientMergeEvent` refuse update/delete in the application. Database-level protection (restricted DB grants, hash chaining) is deferred to Phases 5 and 10.

## Clinical records (added to close Phase 3)

Tables: `encounters`, `conditions`, `medications`, `allergies`, `observations`, `diagnostic_reports`, `document_references`, `referrals`, `record_corrections`, `integration_endpoints`, `sync_jobs`.

- **Provenance on every clinical table (required):** source facility, clinical date, ingestion date, verification status; optional source system/identifier, author practitioner, submitting user, verification method. Verification states: `unverified`, `source_verified`, `clinician_verified`, `patient_entered`.
- **Local codes preserved:** `local_display` is required; `local_code` and a normalized `code_system`/`code` are stored alongside, never replacing it. Observation values and units are stored as supplied (no silent conversion); medication dose/frequency keep original wording.
- **Duplicates:** unique `(source_facility_id, source_identifier)` per table rejects repeat submissions.
- **Corrections:** `record_corrections` holds a write-once original snapshot plus the proposed change, status and resolution. Items marked `entered_in_error` stay stored; `current()` hides them. Nothing is overwritten and corrections cannot be deleted.
- **Conflicts and staleness:** conflicting facility entries are both stored; `stale($days)` takes the freshness threshold from the caller because it is a clinical policy not yet defined.
- **Documents:** `storage_path` is hidden from serialization; documents start with `scan_status = pending`. No upload logic yet (Phase 6).
- **Integration:** `integration_endpoints` default to `sandbox`/`disabled` and store only a `credential_ref`; `sync_jobs` hold an idempotency key and error *code* only, no payloads.
- **Fixtures** (`SyntheticClinicalSeeder`, run via `SyntheticDataSeeder`): valid, missing/unknown, stale, conflicting, duplicate (constraint test), corrected/disputed, wrong-patient (entered in error with trail).

## Still open

Matching score thresholds and the stale-data threshold need clinical-owner policy. Mandatory-field lists per record type, coding systems (e.g. LOINC/SNOMED) and retention periods are not approved; nothing here is a clinical approval. Database-level audit protection is Phase 5/10.
## Migration and rollback

Migrations only create tables (no data changes). Rollback is `php artisan migrate:rollback --step=4`, which drops the four groups in reverse order and **deletes their data**. Verified on MySQL 8.4: migrate, seed twice, rollback, re-migrate. Do not roll back after real data exists; use forward corrective migrations instead.

## Synthetic data

`php artisan db:seed --class=SyntheticDataSeeder` (idempotent). Names and identifiers are prefixed `Synthetic`/`SYN-`; the seeder must never load production exports.
