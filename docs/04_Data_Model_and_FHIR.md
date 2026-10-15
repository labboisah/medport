# Data Model and FHIR Mapping

MediPass | Working specification | October 2026

## Core entities

Patient; PatientIdentifier; Practitioner; Organization/Facility; PractitionerRole; Encounter; ClinicalRecord; Diagnosis/Condition; Medication; MedicationRequest; Allergy; Observation; DiagnosticReport; DocumentReference; Consent; AccessGrant; Referral; AuditEvent; IntegrationEndpoint; SyncJob.

## Record provenance

Every imported or entered clinical item should preserve source facility, source record identifier when available, author or submitting user, creation date, clinical date, ingestion date, verification status, and correction history.

## Patient matching

Use multiple identifiers and demographic signals according to approved policy. Avoid automatic merges where confidence is low. Provide a human-reviewed resolution queue and maintain merge/unmerge audit history.

## FHIR alignment

Map Patient to Patient; encounter-level activity to Encounter; diagnoses to Condition; vital signs and test measurements to Observation; lab reports to DiagnosticReport; files and scanned documents to DocumentReference; consent decisions to Consent; source and transformation details to Provenance; access events to AuditEvent.

## Clinical coding

Use recognized code systems where feasible and retain original local codes and display text. Do not discard the source value when normalization is added.

## Data quality

Validate required fields, units, date formats, reference ranges when provided, duplicate submissions, and source attribution. Keep unknown values unknown rather than inventing or inferring clinical facts.

## Retention and correction

Define retention, archival, correction, and deletion policies with legal and clinical review. Corrections should preserve an audit trail rather than silently overwriting clinical history.
