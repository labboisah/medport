# Glossary and Decision Log

MediPass | Working specification | October 2026

## Glossary

EHR: Electronic Health Record. FHIR: Fast Healthcare Interoperability Resources. Consent: a documented permission or authorization decision. Provenance: information about where a record came from and how it was changed. Break-glass: controlled emergency access. Adapter: component that translates between a partner system and MediPass.

## Decision: product identity

MediPass is the preferred working name. The name, domain, and trademark availability have not been checked.

## Decision: positioning

Position MediPass as a care-network and patient-controlled sharing platform, not merely a personal health passport. Suggested tagline: “Connecting Care.”

## Decision: interoperability

Integrate with Mediflow and third-party systems; do not require EHR replacement. Use standards-based exchange where possible and partner adapters when needed.

## Decision: data architecture

Start with a hybrid model: central patient index and record discovery, source-held records where feasible, and MediPass-held records/documents where explicitly needed. Make source and freshness clear.

## Decision: QR sharing

QR codes contain only a non-sensitive reference or short-lived token. Authentication and authorization are required before records are revealed.

## Open questions

Confirm identity-verification methods; define permitted emergency roles and dataset; determine hosting and residency requirements; finalize retention; define facility commercial terms; validate clinical data set and consent wording.

## Change control

Record major decisions with date, owner, rationale, impact, and review trigger. Revisit decisions after pilot evidence or legal/clinical review.
