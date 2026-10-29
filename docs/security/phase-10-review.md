# Phase 10 security and privacy review (working draft)

Status: **internal self-review only.** It is not the independent security test the guide requires and it does not satisfy the release gate. Scope is synthetic data in a local/CI environment. Severity ratings are mine and need an accountable owner to confirm.

## Controls verified by automated tests

| Area | Control | Evidence |
|---|---|---|
| Authentication | Every API route except health, register and login answers 401 without a token (checked over the whole route table, so a new unguarded route fails CI) | `SecurityHardeningTest` |
| Privilege escalation | Patient and practitioner tokens cannot reach `admin/*` or `integrations/*` | `SecurityHardeningTest`; `AccountAccessTest` |
| Self-registration | Cannot choose account type; identical response for existing email | `AccountAccessTest` |
| Sessions | Tokens expire (60 min default), are revoked on logout and when an account is disabled; sign-in locks after 5 failures; staff and admin cannot sign in without MFA | `AccountAccessTest` |
| Object-level authorization | A patient never sees another patient's records or corrections; consent, facility, purpose and category are checked per request; unknown patient looks the same as no consent | `ClinicalAccessTest`, `ConsentAccessTest`, `WorkflowTest` |
| Tenant isolation | Facility staff see only consented categories; documents are not downloadable without effective consent | `ClinicalRecordTest`, `ConsentAccessTest` |
| Mass assignment | Registration, ingest and clinical writes take validated fields only | `AccountAccessTest`, `IntegrationTest` |
| Upload abuse | Type, size, spoofed content, scan status; storage path hidden; private disk | `ClinicalRecordTest` |
| Replay | Idempotency keys with key-reuse detection; share tokens are opaque, short-lived and hashed | `IntegrationTest`, `ConsentAccessTest` |
| Audit tampering | Audit, merge, correction and snapshot rows are append-only at the model layer; a failed audit write fails the request | `DomainModelTest`, `ConsentAccessTest` |
| Injection | Query builder bindings throughout; SQL-like input is treated as data | `SecurityHardeningTest` |
| Browser hardening | `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, CSP `default-src 'none'`, `Cache-Control: no-store` on every response including errors; HSTS on HTTPS | `SecurityHardeningTest` |
| CORS | Allow-list from `CORS_ALLOWED_ORIGINS`, no wildcard, no credentials | `SecurityHardeningTest` |
| Logs | A database exception is logged as class and SQL state only (its message embeds SQL and bound values, which can hold patient data); 5xx responses never include exception text; audit rows hold metadata, never payloads | `SecurityHardeningTest`; `ConsentAccessTest` |
| Front end | No `dangerouslySetInnerHTML`/`eval`; access token held in memory only and never written to storage (browser test); only a sidebar preference is in `localStorage` | `e2e/hardening.spec.ts` |
| Dependencies | `composer audit` and `npm audit`: no known advisories at the time of this review | manual run |

## Changes made in this phase

- Added the `SecurityHeaders` middleware (global) and `config/cors.php` (the framework default had allowed any origin).
- Database exceptions are no longer written to logs with SQL and bound values.
- Page title was the placeholder `frontend`; now `MediPass`.

## Findings register

| # | Finding | Severity (proposed) | Status | Owner | Retest |
|---|---|---|---|---|---|
| F1 | CORS had no allow-list (framework default `*`) | Medium | Fixed | Engineering | `SecurityHardeningTest` |
| F2 | No security headers on API responses | Medium | Fixed | Engineering | `SecurityHardeningTest` |
| F3 | Query exceptions could log SQL with bound patient values | High if logs were shipped to a shared system | Fixed | Engineering | `SecurityHardeningTest` |
| F4 | Audit immutability is enforced in application code only; a database user with UPDATE/DELETE rights could still alter rows | High for production | **Open** | Infrastructure | Use a DB role without UPDATE/DELETE on audit tables, or triggers; verify |
| F5 | MFA is a flag; there is no TOTP enrolment or verification yet, so staff/admin sign-in depends on the flag being set out of band | High for production | **Open** | Engineering + security owner | Build enrolment, then test |
| F6 | No real antivirus engine; scan result is a placeholder | High for production | **Open** | Infrastructure | Integrate scanner; test with the EICAR file |
| F7 | Tokens live in browser memory only, so a refresh signs the user out; no refresh-token or idle-timeout policy | Low (usability) | Open | Product owner | Needs session policy decision |
| F8 | Encryption at rest, TLS termination, secret rotation, backups and key access are deployment properties and cannot be verified locally | Unknown | **Open** | Infrastructure | Review in the target environment |
| F9 | The front-end static host must send a CSP and the same headers (see below) | Medium | Open | Infrastructure | Check response headers on the deployed host |
| F10 | No abuse monitoring or alerting beyond the admin metrics (failed sign-ins, denied reads) | Medium | Open | Operations | Needs an external monitor |

No finding is rated critical. Items F4 to F6 and F8 must be closed or formally risk-accepted by the accountable owner before any real data is used. Nothing here may be re-rated downward to pass a gate.

## Suggested front-end host headers

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self' <api-origin>; frame-ancestors 'none'; base-uri 'self'; form-action 'self'
X-Content-Type-Options: nosniff
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=()
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

The production build contains no inline scripts, so `script-src 'self'` is expected to work; confirm on the deployed host. The Vite dev server needs inline scripts and is for local use only.

## Privacy review notes

- Notifications carry fixed wording with no names, facilities or clinical detail.
- Audit and the patient access history record who, what category, when and why; never record content.
- Ingested records are labelled unverified and cannot overwrite staff-entered data.
- Open, needs the privacy owner: retention periods, data-subject request handling, lawful basis and cross-border rules, and breach-notification contacts (none are invented here).

## Accessibility

Automated WCAG 2.1 A/AA audits (axe) run in component tests and in the browser for sign-in, the patient and provider screens, notifications and the admin screens. A browser test checks the page reflows at 320 px without horizontal scrolling (data tables scroll inside their own container, which WCAG 1.4.10 allows). **Still required:** screen-reader testing (NVDA, VoiceOver, TalkBack), keyboard review by real users, and accessibility triage with pilot users.

## Performance and recovery

No targets have been approved by pilot stakeholders, so none are asserted. Before the exit gate: agree response-time, upload, concurrency, queue-lag, availability, RPO and RTO targets; then run load and resilience tests on synthetic data and a backup-restore rehearsal.

## Independent testing

Not yet commissioned. Required by the guide before release; findings from it go in the register above with severity, owner, fix and retest evidence.