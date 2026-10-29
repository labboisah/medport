# MediPass API v1: partner integration contract (working default)

Status: working default for **sandbox and synthetic data only**. No partner has accepted this contract; no real partner specification exists yet.

## Authentication and scopes
- `Authorization: Bearer mpk_...`. Keys are issued by a platform admin (`POST /api/v1/admin/integrations`), shown once, stored only as a SHA-256 hash, and expire (default 90 days).
- Scopes are per record category: `write:observations`, `write:conditions`, `write:medications`, `write:allergies`, `write:encounters`, `write:diagnostic_reports`. One out-of-scope item rejects the whole delivery with `403 scope_denied`.
- Unknown key, disabled endpoint, expired key and unverified facility all return the same `401`.
- Partner keys write only. They cannot read patient records.

## Delivery: `POST /api/v1/integrations/records`
- Body: a FHIR R4 Bundle (`fhir_rest` endpoints) or the synthetic `mediflow-synthetic-1` shape (`adapter` endpoints). Max 100 items.
- Every item identifies the patient by an identifier `{system, value}`. Unmatched patients are rejected per item and never created.
- `Idempotency-Key` header (8-100 chars) is required. The same key and same body replays the stored result with `Idempotent-Replay: true`. The same key with a different body is `422 idempotency_key_reused`.
- Response: `{data:{sync_id,status,received,created,updated,skipped_stale,rejected,errors:[{index,code}]}}`. `status` is `succeeded` or `partial`. Errors carry codes only, never record content.

## Source-of-truth rules
- Created records are `unverified` and labelled with the endpoint's source system and facility.
- The source's `meta.lastUpdated` / `updated_at` decides: an older update than the stored `source_updated_at` is skipped (`skipped_stale`).
- A partner never overwrites a record from a different source, a staff-entered record, or one no longer `active` (`conflict_local_record`).

## Errors, retries, dead letter
- Envelope: `{error:{code,message,correlation_id}}`. Codes: `idempotency_key_required`, `idempotency_key_reused`, `malformed_payload`, `too_many_items`, `scope_denied`, `retry_later`, `processing_error`, `dead_letter`.
- A processing failure returns `503` with `Retry-After`. Backoff is 30 s doubling per attempt; retrying early gets `503 retry_later`.
- After 5 attempts the job is dead-lettered (`409 dead_letter`). An admin runs `POST /api/v1/admin/sync-jobs/{id}/replay`, then the partner redelivers with the same key. No payload is stored, so MediPass cannot replay on its own.
- Rate limit: 60 requests per minute per client (`429`).

## Admin visibility
`GET /admin/integrations` (status, scopes, key expiry, last success, job counts), `GET /admin/integrations/{id}/jobs` (last 50), `GET /admin/integrations/{id}/reconciliation` (counts per category and status for that source), `POST /admin/integrations/{id}/disable`.

## Audit
Every delivery, denial and admin action is audited with actor type `integration` or the admin user.