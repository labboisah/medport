# MediPass Backend Modules and Ownership

The backend is a Laravel modular monolith. Modules are namespaced areas under `backend/app`; they will be created as their phases begin. Cross-module calls go through a module's public services, never its models/tables directly.

| Module | Responsibility | Phase | Owner (pending Phase 0) |
|---|---|---|---|
| Identity | Accounts, patient identity, matching | 4 | Clinical safety / Platform |
| Facilities | Facility and practitioner registry | 4 | Product / Platform |
| Authorization | Roles, consent, access grants | 5 | Security / Clinical safety |
| Records | Record index, timeline, clinical data | 6 | Clinical safety |
| Documents | Private document storage | 6 | Security |
| Referrals | Referral workflow | 7 | Product |
| Integrations | API/FHIR, partner adapters | 8 | Platform / Product |
| Audit | Tamper-resistant access logging | 5 | Security |
| Notifications | Email/SMS | 9 | Platform |
| Operations | Health, queues, admin | 2/9 | Security / Operations |

## API conventions (implemented in Phase 2)

- Versioned under `/api/v1`. Liveness `GET /api/v1/health/live`; readiness `GET /api/v1/health/ready` (database, cache, private storage; 503 when degraded).
- Every API response carries `X-Correlation-ID` (valid incoming value is echoed, otherwise a UUID is generated). Do not log clinical payloads, tokens, or secrets.
- Errors use `{ "error": { "code", "message", "correlation_id", "fields"? } }`; 5xx never expose exception details.
- Default rate limit: 120 requests/minute on API routes; tighten per endpoint as sensitive endpoints are added.
- Non-production environments use synthetic data only; credentials and storage are separate per environment and never committed.
