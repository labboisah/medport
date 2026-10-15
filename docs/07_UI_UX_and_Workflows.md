# UI/UX and Workflow Guide

MediPass | Working specification | October 2026

## Patient experience

A simple health-wallet dashboard with recent activity, record categories, sharing controls, consent history, notifications, and help. Use plain language and avoid overwhelming patients with technical terminology.

## Provider experience

A provider workspace that shows verified identity, care purpose, permitted record categories, source facility, dates, and data freshness. Make it obvious when information is unavailable, stale, or unverified.

## Patient-initiated share

Patient chooses recipient or generates a limited share; selects record categories and date range; reviews recipient, purpose, and expiry; confirms; recipient authenticates; access is logged; patient can revoke future access.

## Provider-requested access

Provider authenticates; searches using permitted identifiers; submits a purpose and requested scope; patient is notified and decides; approved data is displayed within scope; all steps are auditable.

## Facility without EHR

Authorized staff sign in; select or confirm patient using approved matching steps; enter encounter metadata and clinical information; attach a scan if necessary; submit for review; corrections retain history.

## Referral

Referring provider selects destination facility/specialist, relevant summary, and supporting documents; patient consent is checked; receiving provider sees referral status and permitted content; outcome can be returned to the record timeline.

## Emergency workflow

Eligible clinician selects emergency access, records reason, confirms identity and role, receives a limited summary, and is shown a visible countdown/expiry. System alerts and flags the event for review.

## Accessibility and connectivity

Responsive layout, keyboard support, readable contrast, clear errors, compressed uploads, save-as-draft where safe, and explicit offline behavior. Do not cache sensitive records on shared devices without a reviewed security design.
