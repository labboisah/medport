# Security, Privacy and Governance

MediPass | Working specification | October 2026

## Governance baseline

Design with Nigeria's Data Protection Act 2023 and applicable health-sector rules in mind. Obtain qualified local legal advice and confirm the current obligations, lawful bases, controller/processor roles, cross-border hosting constraints, and breach-reporting duties before launch.

## Privacy principles

Purpose limitation; data minimization; transparency; accuracy; retention limitation; accountability; privacy by design; patient access and correction processes.

## Access controls

Use role-based access plus patient, facility, purpose, consent, and time constraints. Separate patient, clinician, facility administrator, support, and platform administrator privileges. Require elevated approval for sensitive administrative actions.

## Security controls

Encrypt traffic and stored records; securely manage keys and secrets; use MFA for staff; protect sessions; harden endpoints; scan uploads; validate file types; restrict document downloads; apply rate limits and abuse monitoring.

## Audit

Record who accessed what, when, from which facility, for what purpose, under what authorization, and whether emergency access was used. Protect logs against alteration and restrict access to audit data.

## Emergency access

Require a reason and eligible role; expose only a defined emergency summary; set short expiry; generate alerts; flag for review; document misuse consequences. Define how emergency access behaves when connectivity is unavailable.

## Patient rights and support

Provide accessible explanations, consent management, correction/dispute channels, account recovery, representative/caregiver workflows, and a way to report suspected unauthorized access.

## Incident response

Maintain an incident-response plan covering triage, containment, evidence preservation, risk assessment, notifications, remediation, and post-incident review. Rehearse scenarios before production.

## Clinical safety

Display provenance and freshness; distinguish verified from patient-entered content; avoid presenting a record as a diagnosis or treatment recommendation; require clinical review of workflows that affect care.
