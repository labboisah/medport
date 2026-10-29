# MediPass Phase 0 Decision and Approval Letter

**Status:** Draft for stakeholder review; unsigned and not an approval  
**Prepared:** 2026-10-21  
**Change control:** All proposals below are subject to owner review and may be revised. No legal, clinical, regulatory, security, or production-readiness conclusion is made by this draft.

**To:** MediPass product sponsor and designated decision owners  
**From:** MediPass project team  
**Subject:** Phase 0 scope, governance, and technical-baseline decisions

Dear stakeholders,

Phase 1 local scaffolding is complete. Before Phase 2 begins, the project needs named owners and documented decisions for scope, privacy/legal, clinical safety, platform, and security/operations. This letter requests those decisions and a limited authorization to begin Phase 2 foundation work only after all applicable owners have reviewed and signed.

The current repository describes a patient-controlled health-information sharing service, initially using a modular monolith and a hybrid record model. That is a proposed direction, not an approved product, legal basis, clinical policy, or deployment design.

## Decisions requested

1. **Product and pilot scope — Product/integration owner**
   - Confirm the first-release use cases and explicit exclusions.
   - Identify participating pilot facilities, the Mediflow contact/sandbox, permitted data rights, authoritative sources, and partner responsibilities.
   - Approve pilot acceptance criteria, synthetic test-data approach, and the conditions for stopping or rolling back a pilot.

2. **Privacy and legal review — Legal/privacy owner**
   - Obtain qualified Nigerian legal/privacy review of the Nigeria Data Protection Act 2023 and applicable health-sector rules for the actual operating model.
   - Determine and document controller/processor roles, lawful purposes and bases, transparency and data-subject-rights processes, data minimization, retention/deletion, breach handling, cross-border transfers/residency, data-sharing agreements, and any registration or assessment obligations.
   - Confirm the review scope and sign-off evidence. This draft does not interpret the Act or determine compliance.

3. **Clinical governance — Clinical safety owner, with product/privacy input**
   - Approve the minimum clinical dataset, source/provenance and freshness requirements, and clinical review responsibilities.
   - Define identity verification and uncertain-match handling; no automatic merge of uncertain identities is proposed.
   - Approve correction/dispute workflows, caregiver/representative rules, consent language and withdrawal behavior, and emergency-access eligibility, scope, expiry, notification, and retrospective review.
   - Record clinical hazards, mitigations, and acceptance criteria before any care-affecting workflow is implemented.

4. **Platform and architecture — Platform/technical owner**
   - Confirm or replace the local baseline: Laravel modular monolith, React/TypeScript/Vite frontend, and MySQL for local relational storage. The verified local setup uses PHP 8.3.16, Composer 2.8.4, Node 24.19.0, npm 11.17.0, and MySQL 8.4.3; confirm supported versions before standardizing team or hosted environments.
   - Decide hosting provider, regions/residency, environment topology, authentication provider, private object-storage approach, email/SMS provider, FHIR release/profiles, and partner integration protocol.
   - Redis remains unselected; the locally installed Redis is not enabled pending platform and security review. Local filesystem storage is for development only.

5. **Security and operations — Security/operations owner**
   - Approve the threat-model scope, staff/privileged-user MFA requirements, access-review model, audit-log access and retention, secrets/key management, incident response, backup restoration, RPO/RTO, availability/performance targets, and security acceptance criteria.
   - Define who accepts residual risk and who can authorize a later controlled pilot; this letter does not authorize production use.

## Proposed conditional disposition

Subject to the reviews and signatures below, approve Phase 0’s recorded scope and architecture direction as a working baseline and authorize Phase 2 foundation work. Any owner may request changes or withhold approval. The project must record decisions, rationale, owners, and review triggers in the decision log before starting Phase 2.

This conditional authorization is limited to synthetic-data development, environment separation, API/application foundations, CI and baseline security controls. It does **not** authorize patient-facing clinical workflows, real patient data, partner production integrations, production configuration/deployment, or a live pilot. Those require their own later approvals and acceptance gates.

## Required disposition and sign-off

For each role, select **Approve**, **Approve with conditions**, or **Do not approve**; add the decision/evidence reference, conditions, date, printed name, and signature. An empty or unsigned row is not approval.

| Decision owner | Disposition and evidence/conditions | Name / signature / date |
|---|---|---|
| Product/integration owner | Pending | |
| Legal/privacy owner | Pending | |
| Clinical safety owner | Pending | |
| Platform/technical owner | Pending | |
| Security/operations owner | Pending | |
| Executive sponsor / Phase 0 gate owner | Pending | |

**Phase 0 gate result:** Pending. The project remains blocked from Phase 2 until the required owner decisions are recorded, conflicts are resolved, and the designated gate owner documents the result in `Implementation_Progress.md`.

Sincerely,  
MediPass project team

## Source references

These sources inform review questions; they do not by themselves determine MediPass's legal obligations or approve its design.

- Nigeria Data Protection Commission, [Nigeria Data Protection Act 2023 and other official resources](https://ndpc.gov.ng/resources/). The NDPC's official site also summarizes data-subject rights; counsel should consult the authoritative Act text and current guidance directly.
- Nigeria Data Protection Commission, [official website and data-subject-rights summary](https://ndpc.gov.ng/).
- World Health Organization, [Recommendations on digital interventions for health system strengthening](https://www.who.int/publications/i/item/9789241550505), 2019. The guideline evaluates benefits, harms, feasibility, resource use, and equity, and cautions that digital interventions do not replace functioning health systems.
- HL7 International, [FHIR Release 4 specification](https://hl7.org/fhir/R4/), version 4.0.1. Use only after the platform/integration owners select profiles and confirm partner compatibility.
- OWASP, [Application Security Verification Standard](https://owasp.org/www-project-application-security-verification-standard/), current stable version shown as 5.0.0 when reviewed. A candidate source for application-security verification requirements, not a certification claim.
- NIST, [SP 800-218 Secure Software Development Framework, Version 1.1](https://csrc.nist.gov/pubs/sp/800/218/final), published February 2022. A reference for secure-development practices, not a regulatory requirement for this project.
