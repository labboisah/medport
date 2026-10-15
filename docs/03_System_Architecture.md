# System Architecture

MediPass | Working specification | October 2026

## Recommended starting architecture

Use a modular monolith for the first production pilot, with clear modules and interfaces. Split services only when scale, deployment independence, or team ownership justify it.

## Core components

Patient identity and matching; consent and authorization; record index/timeline; document service; provider and facility registry; integration gateway/adapters; audit and security events; notifications; reporting and operations.

## Hybrid data approach

Keep source records in facility systems where feasible, while maintaining a central patient identity/index and metadata needed to discover available records. MediPass may store records entered directly into its portal and approved documents or summaries. Make source and freshness visible.

## Record access flow

Authenticate user; verify provider and facility; resolve patient with confidence checks; determine care purpose; evaluate consent or emergency policy; fetch permitted data; log access; notify patient where appropriate.

## Interoperability

Use HL7 FHIR resources and profiles where practical, such as Patient, Encounter, Condition, Observation, MedicationRequest, MedicationStatement, DiagnosticReport, AllergyIntolerance, DocumentReference, Consent, and Provenance. Use adapters for systems that cannot speak FHIR.

## Deployment environment

Separate development, test, staging, and production. Keep private document storage separate from public web assets. Use encrypted secrets management, network restrictions, monitoring, and tested backup restoration.

## Resilience

Queue non-urgent synchronization and notifications. Use idempotent integration operations, retries with limits, dead-letter handling, health checks, and visible sync status. Do not silently treat stale data as current.

## Proposed implementation options

A possible stack is a Laravel backend, MySQL, React with TypeScript, Redis for queues/cache, and private S3-compatible object storage. MySQL is the current project baseline; the remaining stack choices are suggestions, not mandatory product requirements, and should be confirmed against team expertise, deployment constraints, and integration needs.
