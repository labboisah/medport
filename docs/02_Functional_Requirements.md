# Functional Requirements

MediPass | Working specification | October 2026

## 1. Identity and account

Patients can register or be onboarded with appropriate identity checks, manage profile details, recover access securely, and nominate a caregiver or representative where legally permitted. Identity matching must avoid automatically merging uncertain records.

## 2. Patient health wallet

Show a chronological view of available encounters, diagnoses, medications, allergies, immunizations, laboratory results, imaging reports, referrals, discharge summaries, and uploaded documents. Display source facility, author when available, date, and whether data is verified or patient-entered.

## 3. Sharing and consent

Patients can initiate a time-limited share, select categories and date ranges, see the recipient, revoke future access, and review access history. A QR code must contain only a non-sensitive reference or short-lived token; scanning it alone must not expose records.

## 4. Provider access

Verified providers can request access for a stated care purpose. The patient can approve, deny, or limit the request. Access is role-based and scoped to the approved patient, data categories, and duration.

## 5. Emergency access

Support break-glass access only for eligible authenticated clinicians, with a reason, narrow emergency summary, short expiry, prominent warning, audit event, patient notification when feasible, and retrospective review. Emergency access must not become a general bypass.

## 6. Facility portal

Facilities without EHRs can securely enter encounters and upload scanned documents through a browser-based portal. Provide duplicate checks, mandatory source/date fields, review status, and correction workflows.

## 7. EHR integrations

Support documented APIs and facility-specific adapters. Prefer standards-based exchange such as HL7 FHIR where supported. Record synchronization status, errors, source identifiers, and last successful sync.

## 8. Administration

Manage facilities, staff roles, provider verification, integrations, consent policies, incident reviews, retention settings, and operational reports.

## 9. Notifications

Notify patients about access grants, access events, revocations, and security-relevant changes using available channels and user preferences. Avoid exposing sensitive clinical details in notification previews.

## 10. Non-functional requirements

Strong access control; encryption in transit and at rest; auditable changes; backup and recovery; accessible responsive UI; low-bandwidth support; reliable monitoring; defined retention and deletion rules.
