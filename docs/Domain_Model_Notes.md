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

## Deferred (clinical tables, Phase 6)

Encounter, condition, medication, allergy, observation, diagnostic report, document reference, referral, integration endpoint and sync job, with the provenance fields (source facility/identifier, author, clinical and ingestion dates, verification, correction history). Stale, conflicting and corrected-record fixtures belong with them. Matching *scoring thresholds* are not defined; seeded scores are illustrative only.

## Migration and rollback

Migrations only create tables (no data changes), so rollback is `php artisan migrate:rollback --step=3`, which drops the three groups in reverse order. Verified on MySQL 8.4: migrate, rollback, re-migrate. Do not roll back after real data exists; use forward corrective migrations instead.

## Synthetic data

`php artisan db:seed --class=SyntheticDataSeeder` (idempotent). Names and identifiers are prefixed `Synthetic`/`SYN-`; the seeder must never load production exports.
