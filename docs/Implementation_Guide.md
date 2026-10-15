# MediPass Implementation Guide

**Guide status:** Draft for implementation  
**Phase status:** Phase 0 is `Blocked`; Phase 1 is `In progress` for a user-authorized local scaffold only; all later phases are `Not started`.  
**Completion target:** A tested release candidate with production-readiness evidence. This guide does not authorize deployment with real patient data.

## Purpose and scope

This guide turns the product, architecture, security, workflow, testing, deployment, roadmap, and checklist documents into a sequenced implementation path. It covers repository discovery, dependency installation, application build-out, integration, end-to-end system testing, staging, and the production go/no-go review.

The first release is a patient-controlled health-information sharing platform. It must support a patient health wallet, verified provider access, facility onboarding, scoped consent, audit history, referrals, a secure portal for facilities without an EHR, and a Mediflow integration proof of concept. It must preserve provenance and freshness and must not silently merge uncertain patient identities or present incomplete data as complete.

This is an engineering plan, not a legal, clinical, security, or regulatory approval. Before handling real health data, obtain qualified Nigerian legal and healthcare review and the operational approvals identified below.

## Supporting documents

Use these documents together; this guide sequences their requirements rather than replacing them:

- [Product Overview](01_Product_Overview.md)
- [Functional Requirements](02_Functional_Requirements.md)
- [System Architecture](03_System_Architecture.md)
- [Data Model and FHIR Mapping](04_Data_Model_and_FHIR.md)
- [API and Integration Guide](05_API_and_Integration_Guide.md)
- [Security, Privacy and Governance](06_Security_Privacy_and_Governance.md)
- [UI/UX and Workflow Guide](07_UI_UX_and_Workflows.md)
- [Testing and Quality Plan](08_Testing_and_Quality_Plan.md)
- [Deployment and Operations](09_Deployment_and_Operations.md)
- [Roadmap and Risk Register](10_Roadmap_and_Risk_Register.md)
- [Glossary and Decision Log](11_Glossary_and_Decisions.md)
- [Implementation Checklist](Implementation_Checklist.md)

## Status model

Keep the status of each phase in this guide or in the progress record created during Phase 0. A phase is not complete because code exists; it is complete only when its exit criteria have evidence.

| Status | Meaning |
|---|---|
| Not started | No implementation work for this phase has been verified. |
| In progress | Work is underway; the exit gate has not passed. |
| Blocked | Work cannot safely continue until a named decision, dependency, or approval is resolved. Record the owner and required action. |
| Complete | All exit criteria passed; record test commands, results, and relevant changes. |

## Agent operating and handoff rules

1. Start each session by reading this guide, the progress record, the relevant supporting documents, the repository instructions, and `git status`. Do not overwrite or discard existing work.
2. Work in phase order. The only allowed work before Phase 0 approvals are complete is an explicitly authorized, isolated Phase 1 scaffold using synthetic data and local services; do not implement patient-data workflows or proceed to Phase 2. Tests and security controls begin early and continue in every phase; Phase 11 is the full-system verification, not a substitute for earlier tests.
3. At a phase boundary, mark the phase `Complete` only with evidence, then continue to the next phase unless an approval gate or blocker requires a stop.
4. If a test fails, a security boundary is unclear, or a policy decision is unresolved, keep the phase `In progress` or mark it `Blocked`. Do not skip ahead and do not claim production readiness.
5. At every stop, update `docs/Implementation_Progress.md` with the current phase and status, completed work, changed files, exact checks and outcomes, unresolved decisions and their owners, and the next concrete action. The next agent resumes from that action after checking current repository state; it does not repeat completed phases or assume undocumented work passed.
6. Stop before any production deployment, real-patient-data import, or real-patient-data test. Those actions require explicit authorization and the Phase 13 go/no-go approvals.
7. Never invent clinical facts, legal interpretations, consent wording, patient-match thresholds, retention rules, or emergency-access eligibility. Record unresolved items as blockers for the designated clinical, legal, privacy, or product owner.

## Proposed technical baseline

The current project baseline is a Laravel backend, MySQL, React with TypeScript, Redis for queues/cache, and private S3-compatible object storage. MySQL is the selected database; confirm its supported version and hosting provider before setup. The remaining infrastructure choices still require confirmation. Use a modular monolith for the first pilot. Keep the initial implementation in one deployable backend with explicit modules and interfaces; do not split services without an approved operational reason.

Phase 0 must confirm or replace this baseline before scaffolding. Select exact supported versions from official compatibility requirements at implementation time and commit lockfiles. Choose the hosting provider, identity/authentication approach, FHIR profiles, object-storage implementation, email/SMS provider, and integration protocol with the appropriate owners. Do not assume that a proposed technology or this guide has already been approved.

## Phase 0 — Repository, scope, and decisions

**Status:** Blocked  
**Depends on:** None

### Work

- Inspect the complete repository, instructions, branch, uncommitted changes, existing manifests, CI, tests, and deployment configuration. The current workspace snapshot contains documentation only; if application code or manifests have since appeared, preserve and extend them rather than scaffolding a duplicate application.
- Confirm the MVP scope and explicitly defer items outside the first release, including EHR replacement, unrestricted record search, autonomous diagnosis, and unreviewed AI-generated clinical conclusions.
- Confirm the proposed technical baseline and hosting approach. MySQL is the selected database baseline; confirm its supported version and hosting provider. Record exact runtime and service versions, environments, supported browsers/devices, and local development method.
- Assign decision owners and resolve or track: lawful purposes and controller/processor roles; residency and hosting constraints; patient identity and match-review policy; caregiver/representative rules; consent wording and withdrawal behavior; record correction and retention; emergency roles and emergency summary; audit retention; minimum clinical dataset and coding systems; partner data rights and authoritative fields; outage behavior; service availability, RPO, and RTO.
- Create `docs/Implementation_Progress.md` with an entry for every phase, current status, decision log, and next action. Keep sensitive values and patient data out of this file.

### Exit gate

Scope, architecture direction, decision owners, and the first-pilot facility and integration assumptions are recorded. Any decision needed to avoid unsafe design is resolved or explicitly marked as a blocker with an owner and due action. No unapproved health-data handling is planned.

### Stop and resume checkpoint

If legal, clinical, identity, consent, or hosting decisions block safe architecture, mark Phase 0 `Blocked` and stop. Resume by resolving the named decision, updating the progress record, then rechecking repository state before Phase 1.

## Phase 1 — Install dependencies and bootstrap development

**Status:** In progress  
**Depends on:** Phase 0 complete for application features. A user-authorized local-only scaffold may start while Phase 0 remains blocked, provided no patient data, patient-facing clinical workflows, external integration, or production configuration is introduced.

### Prerequisites

Install and verify the versions compatible with the approved framework and hosting target:

- Git and the project’s required shell.
- A supported PHP runtime and required PHP extensions, plus Composer 2, if Laravel is confirmed.
- A supported Node.js LTS release and npm if React/Vite is confirmed.
- MySQL for relational data; Redis if background queues/cache are approved.
- A private S3-compatible storage service for local development or an approved local equivalent. Production objects must never be placed in a public web directory.
- A local mail catcher and synthetic-only integration endpoints for development. Docker may be used to make local services repeatable but is not itself a product requirement.

### Recommended frontend stack

Confirm this stack in Phase 0 and use the repository's existing choices if application code already exists:

- React with TypeScript and Vite for the client application.
- Tailwind CSS with its Vite plugin for styling; use accessible, composable UI primitives such as shadcn/ui (Radix-based) only if the team approves the added component dependency.
- React Router for client-side navigation and Lucide React for interface icons.
- TanStack Query for API request/cache state; React Hook Form and Zod for form state and validation.
- Vitest, React Testing Library, and `user-event` for component tests; Playwright for browser-level workflow tests.
- TypeScript strict mode and the Vite template's ESLint setup. Add Prettier only if the repository does not already use an equivalent formatter.

Keep API authorization on the server. Do not persist clinical data or access tokens in `localStorage`, IndexedDB, or a service-worker cache. If TanStack Query is used, keep clinical responses in memory only and clear its cache on logout, account change, or facility-context change. Confirm every package's compatibility and pin versions in the lockfile.

On Windows, verify installed tools from PowerShell with `php -v`, `composer --version`, `node --version`, `npm.cmd --version`, and the relevant database/cache client commands. Use `npm.cmd` if PowerShell blocks `npm.ps1` under the local execution policy; do not weaken the machine-wide policy just to run npm. Check PHP extensions with `php -m`; at minimum confirm the extensions required by the selected Laravel release and MySQL driver. Do not continue by guessing a missing or unsupported version.

### Installation sequence

- If the repository already has `composer.json`, `composer.lock`, `package.json`, and lockfiles, use the existing project and install from its lockfiles (`composer install` and `npm ci`). Do not recreate or upgrade the project as part of routine setup.
- If the repository is still documentation-only and Phase 0 approved the proposed split application, scaffold into clearly named backend and frontend directories. Example starting commands, subject to approved versions and repository policy:

  ```powershell
  composer create-project laravel/laravel backend
  npm create vite@latest frontend -- --template react-ts
  ```

- In the frontend directory, install the approved styling, routing, API-state, and form packages. The template may already provide some lint/test packages; inspect `package.json` first and add only what is missing:

  ```powershell
  Set-Location frontend
  npm install tailwindcss @tailwindcss/vite lucide-react react-router-dom @tanstack/react-query react-hook-form zod @hookform/resolvers
  npm install -D vitest @testing-library/react @testing-library/user-event @testing-library/jest-dom jsdom @playwright/test
  npx playwright install
  ```

- Configure Tailwind CSS for Vite by registering `@tailwindcss/vite` in `vite.config.ts` and importing `tailwindcss` from the application stylesheet. If shadcn/ui is approved, initialize it using its current official instructions and commit the generated configuration and components.
- Add frontend scripts for linting, type checking, unit/component tests, production build, and Playwright end-to-end tests. Ensure the CI setup runs these scripts; do not treat a successful frontend build as a substitute for workflow tests.

- Configure MySQL, Redis, and private object storage using local-only endpoints and credentials. Copy `.env.example` to `.env` in PowerShell with `Copy-Item .env.example .env`; generate the framework application key using the framework command. Never commit `.env`, credentials, private keys, test tokens, or real patient data.
- Add only dependencies required by approved design decisions. Record the purpose and maintenance/security posture of each major package. Commit composer and npm lockfiles.
- Add a root-level setup guide or scripts so a new developer can reproduce dependency installation and start the services without undocumented machine state.

### Exit gate

A clean checkout can install locked dependencies, boot the backend and frontend locally, connect to the development database and approved local services, and run their baseline tests/builds. Secrets are excluded from version control; synthetic data is used. Record exact commands and versions in the progress record.

### Stop and resume checkpoint

Stop if compatibility, package ownership, service access, or secrets handling is unresolved. Resume at the failed prerequisite or install command; do not scaffold a second app or upgrade lockfiles without recording why.

## Phase 2 — Application foundation, environments, and CI

**Status:** Not started  
**Depends on:** Phase 1 complete

### Work

- Establish backend modules and ownership boundaries for identity, facilities/providers, consent/authorization, record index and clinical data, documents, referrals, integrations, audit, notifications, and operations.
- Define API versioning, request validation, consistent error shapes, correlation IDs, pagination, rate-limit behavior, and health/readiness endpoints.
- Separate development, test, staging, and production configuration. Keep separate credentials and storage buckets. Use synthetic data in non-production environments.
- Add formatting, static analysis/type checks, dependency vulnerability checks, unit/integration test jobs, and frontend production-build checks to CI. Protect mainline changes with review and required checks.
- Add structured operational logging that excludes clinical payloads, access tokens, secrets, and unnecessary personal data. Add request correlation without exposing sensitive identifiers.
- Create migrations, seed-data conventions, and a reproducible local startup command. Add health checks for database, cache/queue, and private storage.

### Exit gate

A clean checkout boots through documented commands; CI passes on a clean branch; environment-specific secrets are not committed; health checks detect unavailable dependencies; code ownership and module boundaries are documented.

### Stop and resume checkpoint

Stop if local setup only works through undocumented manual state or a CI gate is red. Record the exact failing command and resume with reproducibility/CI before adding product features.

## Phase 3 — Domain model, migrations, and data quality

**Status:** Not started  
**Depends on:** Phase 2 complete

### Work

- Implement the approved entities from the data model: patient and identifiers, practitioner, facility/organization, practitioner role, encounter, clinical record, condition, medication, allergy, observation, diagnostic report, document reference, consent, access grant, referral, audit event, integration endpoint, and sync job as appropriate to the first release.
- Preserve source facility, source identifier, author/submitting user, clinical date, ingestion date, verification state, and correction history for every record that supports those fields.
- Define database constraints, indexes, deletion/retention behavior, tenant/facility boundaries, and foreign-key rules. Enforce required source/date fields; preserve original local codes and display text when normalized codes are added.
- Implement duplicate detection and uncertain-match review as explicit workflows. Never automatically merge identities below an approved confidence policy. Record merge/unmerge history.
- Add deterministic synthetic fixtures for valid, missing, stale, conflicting, duplicate, corrected, and wrong-patient scenarios. Never use production exports as fixtures.
- Keep migrations forward-safe and review rollback behavior before any deployment. Corrections must not silently erase clinical history.

### Exit gate

Migrations build a clean database; constraints and data-quality tests pass; provenance and correction history are represented; match uncertainty is preserved; fixtures contain no real patient information; migration/rollback behavior is documented.

### Stop and resume checkpoint

Stop on unresolved identity policy, missing provenance, unsafe destructive migration, or ambiguity over authoritative source data. Resume with the schema or policy decision named in the progress record.

## Phase 4 — Authentication, patient identity, facility and provider registry

**Status:** Not started  
**Depends on:** Phase 3 complete

### Work

- Implement secure patient registration/onboarding, account recovery, session management, and identity verification according to the approved policy. Keep account authentication separate from clinical identity matching.
- Implement facility registration and verification, practitioner profiles, provider verification, facility membership, and role assignment. Privileged staff and administrators require MFA; apply stronger controls to high-impact operations.
- Implement safe patient search using only approved identifiers and minimum necessary results. Route uncertain matches to human review; support correction and auditable merge/unmerge where approved.
- Prevent account enumeration and unauthorized disclosure through generic errors, scoped search, rate limits, and abuse monitoring.
- Test role changes, disabled users, expired sessions, recovery, and account compromise handling.

### Exit gate

Authentication and provider/facility verification tests pass; uncertain patient matches do not auto-link; role and facility boundaries are enforced server-side; staff MFA and recovery controls meet the approved policy.

### Stop and resume checkpoint

Stop if identity verification, patient matching, or representative/caregiver policy is undefined. Do not substitute a convenient identifier for an approved identity policy.

## Phase 5 — Authorization, consent, and audit before clinical access

**Status:** Not started  
**Depends on:** Phase 4 complete

### Work

- Implement authorization as a server-side decision using authenticated identity, verified provider/facility, care purpose, patient, requested categories, time window, consent/access grant, and expiry. A hidden UI control is not an access-control boundary.
- Support patient-initiated, time-limited sharing; provider-requested access; approve/deny/limit decisions; future revocation; access history; and notification triggers according to approved consent rules.
- Ensure denial, expiry, revocation, wrong facility, wrong purpose, and out-of-scope categories fail closed. QR codes carry only a non-sensitive reference or short-lived opaque token; scanning is never sufficient authorization.
- Add append-only audit events for access attempts and outcomes, consent changes, administrative changes, emergency access, record correction, and integration operations. Capture actor, patient/record scope, facility, purpose, time, authorization basis, and outcome without copying the clinical payload into logs.
- Protect audit data from unauthorized alteration and restrict audit access. Define how failed audit writes affect access; fail closed for protected clinical reads if required audit cannot be recorded.
- Add notification content rules that prevent sensitive clinical details from appearing in lock-screen previews or untrusted channels.

### Exit gate

Automated tests prove least privilege, scope enforcement, consent denial, expiry, revocation, audit completeness, and non-sensitive QR behavior. Unauthorized requests cannot retrieve clinical payloads by changing client parameters or calling APIs directly.

### Stop and resume checkpoint

This is a hard safety gate. Do not expose clinical records in UI, APIs, exports, or integrations until Phase 5 passes. Stop on any bypass, missing audit evidence, or unresolved consent rule.

## Phase 6 — Clinical records, timeline, and secure document handling

**Status:** Not started  
**Depends on:** Phase 5 complete

### Work

- Implement the patient timeline and approved first-release record types. Show source facility, author when available, clinical date, ingestion date, verification state, and freshness for each item.
- Distinguish source-system records, staff-entered records, patient-entered content, and imported documents. Preserve original values, units, local codes, and source references.
- Implement private document upload/download with allowlisted types, size limits, content inspection/malware scanning, generated storage keys, authorization checks on every retrieval, and short-lived access URLs only if reviewed. Do not trust filename or client MIME type; do not serve uploaded files from a public directory.
- Handle corrections by adding a traceable correction/history entry rather than silently replacing a prior clinical record. Display unavailable, stale, unverified, and patient-entered states plainly.
- Define deletion, archival, and retention behavior only after legal and clinical approval. Keep data completeness claims honest when a facility is disconnected or history is partial.

### Exit gate

Patient and provider views show only authorized data; provenance/freshness is visible; upload abuse and unauthorized download tests pass; corrections retain history; stale and incomplete-source states are visible.

### Stop and resume checkpoint

Stop if storage is publicly reachable, file validation is bypassable, data provenance is lost, or the interface implies an incomplete timeline is comprehensive. Resume with the relevant authorization, storage, or display defect.

## Phase 7 — Patient, provider, facility, and referral workflows

**Status:** Not started  
**Depends on:** Phase 6 complete

### Work

- Build the patient wallet with recent activity, record categories, sharing controls, consent history, notifications, account/help flows, and correction/dispute entry points.
- Build provider workspace showing verified identity, facility, stated care purpose, permitted categories, source/date/freshness, and clear unavailable/stale/unverified states.
- Complete patient-initiated sharing and provider-requested access end to end, including review of recipient, purpose, category/date scope, expiry, decision, revocation, and access history.
- Build the no-EHR provider portal with approved patient matching, encounter entry, required source/date fields, document attachment, duplicate checks, review status, and correction history. Clearly label manual entry.
- Build referral creation and receipt with destination, relevant summary/documents, consent check, status, and return outcome to the timeline.
- Apply responsive layouts, keyboard access, readable contrast, plain-language errors, low-bandwidth behavior, and explicit offline/pending states. Never cache sensitive records on shared devices without a reviewed design.
- Use role-based end-to-end tests, keyboard-only workflows, and usability sessions with representative pilot users.

### Exit gate

The primary patient, provider, facility-entry, and referral workflows complete without bypassing Phase 5 authorization; users can distinguish source and freshness; accessibility and usability findings are triaged with no release-blocking barriers.

### Stop and resume checkpoint

Stop if the UI asks users to infer consent, authorization, or clinical meaning from ambiguous labels. Resume with workflow clarification and re-test the affected role journey.

## Phase 8 — Versioned API, FHIR mapping, and partner integrations

**Status:** Not started  
**Depends on:** Phases 3–7 complete

### Work

- Publish versioned API contracts and least-privilege scopes for approved resource groups. Use HTTPS, strict validation, pagination, rate limits, correlation IDs, consistent errors, and idempotency keys for repeatable writes.
- Map approved resources to FHIR where practical: Patient, Encounter, Condition, Observation, MedicationRequest/MedicationStatement, DiagnosticReport, AllergyIntolerance, DocumentReference, Consent, Provenance, and AuditEvent. Preserve local codes and source data; document profiles, terminology, version, and unsupported elements.
- Implement the Mediflow proof-of-concept adapter behind an integration boundary. Do not leak partner-specific assumptions into core domain logic. Use contract tests and synthetic sandbox data before partner data.
- Track source identifiers/version, last successful sync, retry count, conflict state, and actionable error details. Define field authority; prevent stale updates from overwriting newer source data.
- Make synchronization idempotent; bound retries; use backoff and dead-letter handling; make sync status visible; provide reconciliation and safe replay procedures.
- Test invalid/expired tokens, wrong scopes, malformed FHIR, duplicate webhook delivery, rate limiting, partner outage, prolonged disconnection, conflict, replay, and recovery.

### Exit gate

Versioned contracts and mappings are documented; authentication/scoping and contract tests pass; repeated deliveries do not duplicate data; stale records do not overwrite newer records; failure and recovery status is visible. Partner acceptance is recorded for the pilot integration.

### Stop and resume checkpoint

If a real partner sandbox, agreement, or test credentials are unavailable, implement and test a clearly labeled synthetic adapter only. Mark partner acceptance `Blocked`; do not claim the real integration or production data exchange is complete.

## Phase 9 — Notifications, administration, queues, and operations

**Status:** Not started  
**Depends on:** Phases 2, 5, and 8 complete

### Work

- Implement notification preferences and approved events for access grants, access events, revocation, and security-relevant changes. Keep messages minimal and avoid sensitive clinical details.
- Implement controlled administration for facilities, staff roles, provider verification, integrations, policy settings, incident review, retention configuration, and operational reporting. Require least privilege and audit high-impact changes.
- Implement background queues for non-urgent synchronization and notifications. Provide retry limits, dead-letter inspection, idempotent workers, queue lag metrics, and safe reprocessing.
- Monitor uptime, latency, error rates, failed logins, suspicious access, queue lag, storage capacity, sync failures, and integration health. Redact tokens and clinical payloads.
- Document runbooks for deployment, rollback, restore, key rotation, account compromise, suspected breach, partner outage, incorrect patient match, failed sync, and emergency-access review.

### Exit gate

Notifications obey privacy rules; administrative actions are scoped and audited; queued work recovers safely after retries/outage; operational metrics and runbooks are usable in a rehearsal.

### Stop and resume checkpoint

Stop if operations depend on unlogged manual changes, notification previews reveal health details, or failed sync/queue work can disappear silently. Resume after remediation and repeat outage/recovery tests.

## Phase 10 — Security, privacy, accessibility, and performance hardening

**Status:** Not started  
**Depends on:** Phases 4–9 complete

### Work

- Review threat model and data flows. Verify TLS, secure cookies/session settings, CSRF protections where applicable, content security policy, secret/key handling, encryption at rest, dependency updates, file scanning, rate limits, abuse monitoring, and least privilege.
- Test object-level authorization, facility/tenant isolation, direct-object-reference attacks, mass assignment, injection, XSS, CSRF, SSRF, upload abuse, replay, session fixation, privilege escalation, and audit tampering.
- Verify access to logs, backups, keys, and production data is minimized and reviewed. Ensure logs/metrics/traces do not contain clinical payloads or secrets.
- Conduct accessibility checks on critical journeys, including keyboard-only operation, labels, focus order, error announcements, contrast, zoom/responsive behavior, and assistive technology as available.
- Establish measurable response-time, upload, concurrency, queue-lag, availability, and recovery targets with pilot stakeholders. Run load and resilience tests against synthetic data; do not invent targets unsupported by user needs.
- Obtain independent security testing appropriate to the risk. Track findings by severity, owner, remediation, and retest evidence. No unresolved critical/high finding may pass the release gate.

### Exit gate

Security and privacy review is documented; critical/high security issues are closed and retested; accessibility blockers are resolved or formally accepted by the accountable owner; performance and recovery results meet approved targets.

### Stop and resume checkpoint

Stop for unresolved critical/high vulnerabilities, unreviewed privacy risks, sensitive data leakage, or missing authorized risk acceptance. Do not lower severity to force release.

## Phase 11 — Complete system and acceptance test

**Status:** Not started  
**Depends on:** Phases 2–10 complete

### Test layers

Run unit, module integration, API contract, database/migration, UI component, end-to-end, accessibility, security, performance, partner acceptance, backup/restore, and operational-rehearsal tests. Tests must run on a clean environment using synthetic data and documented setup.

### Mandatory end-to-end scenarios

- Patient can register/sign in, recover access safely, view only their own timeline, and understand source, date, freshness, and verification state.
- A verified provider requests a stated-purpose scope; the patient approves, denies, limits, or revokes; only the granted scope is returned; expiry and revocation stop later access.
- A provider at the wrong facility, with the wrong role/purpose, or without consent is denied at the API and data layers, not just in the UI.
- QR/share references expose no clinical data before authentication and authorization; expired or replayed tokens fail safely.
- Uncertain patient matches require review; wrong-patient, duplicate, correction, and merge/unmerge cases preserve history and audit.
- Facility staff can enter an encounter and controlled document; records show manual-entry provenance; duplicate and correction workflows work.
- Referral content is consent-checked; receiver sees only permitted content; referral status/outcome is traceable.
- Eligible emergency access requires reason and role, returns only the approved emergency summary, expires on time, creates alert/audit/review items, and cannot be used as general access.
- Uploads reject disallowed/oversized/malicious files; private documents cannot be fetched without authorization; deleted/revoked access does not leave public copies.
- Partner integration handles retries, duplicate delivery, stale versions, outage, malformed data, rate limits, recovery, and visible sync status without creating silent data loss.
- Notifications, administrative actions, incident workflows, and audit review operate with least privilege and no sensitive preview/log leakage.
- Backup restoration and rollback are performed from documented instructions and produce a verified, usable environment.

### Suggested command set

Use the scripts present in the repository and record their exact names/results. Typical commands, where configured, include `php artisan test`, `composer test`, `npm run lint`, `npm run typecheck`, `npm run test`, `npm run build`, and the configured browser end-to-end suite. Do not report a command as passed if it was not run; do not add placeholder scripts that return success without testing.

### Exit gate

All required suites pass on a clean CI run; every mandatory scenario has recorded evidence; there are no open critical/high defects; failed tests have owners and disposition; the test report identifies environment, commit, versions, test data class, commands, results, and residual risks.

### Stop and resume checkpoint

Any failure in consent, authorization, matching, audit, record provenance, emergency access, or integration integrity blocks progression. Fix and rerun the failing suite plus affected regression tests before continuing.

## Phase 12 — Staging, release rehearsal, and operational readiness

**Status:** Not started  
**Depends on:** Phase 11 complete

### Work

- Deploy a release candidate to staging using production-like configuration, separate credentials, synthetic data, private storage, and reviewed network restrictions.
- Exercise migrations, application startup, health checks, monitoring/alerts, queue workers, integration sandbox, rate limits, backup creation/restoration, rollback, and release notes.
- Verify secrets are supplied through approved secret management; rotate test credentials; verify no debug mode, development endpoints, test accounts, permissive CORS, or public document storage is enabled.
- Rehearse incident response, suspected breach, compromised staff account, wrong-patient match, partner outage, restore, and emergency-access review with named owners and escalation paths.
- Confirm support coverage, facility contacts, patient help channels, escalation severity, after-hours plan, and third-party integration ownership.
- Obtain pilot facility acceptance and complete staff training using synthetic training data.

### Exit gate

Staging deployment, migrations, rollback, restore, alerts, runbooks, support contacts, training, and pilot acceptance are demonstrated and recorded. The release candidate is reproducible from source and approved configuration.

### Stop and resume checkpoint

Stop if restore/rollback fails, critical alerts are missing, staff cannot execute runbooks, or staging configuration differs materially from the proposed production setup. Resume at the failed rehearsal and repeat it.

## Phase 13 — Production-readiness decision and controlled pilot gate

**Status:** Not started  
**Depends on:** Phase 12 complete

### Required go/no-go evidence

- Product, clinical, privacy/legal, security, operations, and pilot-facility owners approve the specific pilot scope and data flows.
- Patient matching, consent, access control, audit, provenance, correction, emergency access, and referral scenarios pass acceptance tests.
- No unresolved critical/high security issue; residual risks have named owners and documented acceptance.
- Data-sharing agreements, controller/processor responsibilities, lawful purposes, retention/deletion, residency, breach response, and patient-rights processes are approved by qualified local reviewers.
- RPO/RTO, service targets, monitoring, backup/restore, rollback, incident response, support coverage, and partner escalation are tested.
- Pilot metrics and stop conditions are defined, including incorrect match, unauthorized access, consent failure, unsafe stale data, significant outage, and suspected breach.

### Exit gate and stop point

The deliverable is a release candidate plus a signed go/no-go record and evidence pack. Stop here unless an authorized release owner explicitly approves deployment and real-data use. Start with a controlled, limited pilot and agreed monitoring; do not expand scope or facilities without reviewing pilot evidence.

### Resume checkpoint

If go/no-go is `No-Go` or `Blocked`, record each gap, accountable owner, due action, and the phase to resume. The next agent resumes at that phase’s failed exit criterion, reruns affected tests, and returns for the required approvals. Never mark a legal, clinical, or operational approval complete based solely on code or an AI-generated assessment.

## Production-readiness evidence pack

Before a go decision, assemble and link:

- Approved architecture, data-flow and threat-model diagrams, versioned API/FHIR contracts, and decision log.
- Requirements-to-test traceability and full test/CI reports tied to a release commit.
- Security assessment, findings, remediation/retest evidence, and accepted residual risks.
- Privacy/legal/clinical approvals and data-sharing/processing agreements.
- Migration, deployment, rollback, backup/restore, monitoring, alerting, and incident-response runbooks.
- Pilot facility acceptance, training completion, support/escalation roster, pilot metrics, and stop conditions.
- Release notes, dependency versions/lockfiles, secrets-management evidence, and a data-handling statement confirming synthetic data was used in non-production tests.
