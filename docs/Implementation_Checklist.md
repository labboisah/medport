# MediPass Implementation Checklist

## Discovery and governance
- [ ] Validate patient and provider workflows with pilot facilities.
- [ ] Obtain Nigerian privacy and healthcare legal review.
- [ ] Define controller/processor responsibilities and data-sharing agreements.
- [ ] Define patient matching and identity verification policy.
- [ ] Define retention, correction, representative, and emergency-access policies.

## Product foundation
- [ ] Facility and practitioner registry with verification.
- [ ] Patient account and health timeline.
- [ ] Provenance and freshness on every record.
- [ ] Consent, time-limited sharing, revocation, and access history.
- [ ] Secure document upload and private storage.
- [ ] Audit logging and security monitoring.
- [ ] Provider portal for facilities without EHRs.

## Interoperability
- [ ] Document versioned API contracts.
- [ ] Define FHIR mappings and local code handling.
- [ ] Build Mediflow proof-of-concept adapter.
- [ ] Build sandbox and synthetic test data.
- [ ] Add retry, idempotency, conflict, and sync-status handling.

## Security and operations
- [ ] MFA for staff and privileged users.
- [ ] Encryption in transit and at rest.
- [ ] Secrets and key rotation process.
- [ ] Incident response and breach procedures.
- [ ] Backup restoration and rollback drills.
- [ ] Access reviews and emergency-access retrospective reviews.

## Pilot readiness
- [ ] Train facility staff.
- [ ] Pass consent, authorization, patient-matching, and audit tests.
- [ ] Close critical/high security findings.
- [ ] Confirm support contacts and escalation process.
- [ ] Define pilot metrics and a go/no-go review.
