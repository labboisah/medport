# MediPass runbooks (working drafts)

Drafts for rehearsal on synthetic data. The platform/security owner must review them before any pilot; contact roles, RPO/RTO and notification duties are **not decided** and are marked TBD. Never paste patient identifiers or tokens into incident notes, tickets or chat.

Tools: admin API under `/api/v1/admin` (`ops/metrics`, `ops/failed-jobs`, `incidents`, `audit`, `integrations`, `policy`). Log every manual step as an incident update or audit entry; unlogged manual changes are a stop condition.

## Queue worker
Run `php artisan queue:work --tries=3 --max-time=3600` under a supervisor. Watch `queue.lag_seconds` and `queue.failed` in `ops/metrics`. Failed work is listed (job type and exception class only) at `ops/failed-jobs`; requeue with `POST ops/failed-jobs/{id}/retry`. Workers are idempotent (notifications dedupe on a key), so retry is safe.

## Deployment
1. Confirm CI is green on the commit. 2. Take a database backup. 3. Put the app in maintenance (`php artisan down`). 4. Deploy, `composer install --no-dev`, `php artisan migrate --force`, `config:cache`, `route:cache`. 5. Restart workers. 6. `php artisan up`; check `/api/v1/health/ready` and `ops/metrics`.

## Rollback
Redeploy the previous release. Migrations are additive; if one must be reverted, restore the pre-deploy backup instead of `migrate:rollback` on production data. Record an incident.

## Backup and restore
Back up the database and the private document disk together. Restore into a scratch environment first, run `php artisan migrate:status` and the readiness check, then compare `integrations/{id}/reconciliation` counts. Backup schedule and retention: TBD (owner).

## Key rotation
- Partner key: create a new integration key (`POST integrations`), give it to the partner out of band, confirm a delivery, then `disable` the old endpoint.
- App key / database credentials: TBD by the platform owner; rotating `APP_KEY` invalidates sessions and encrypted values, so plan a window.

## Account compromise
Open an `account_compromise` incident. Disable the account (`POST users/{id}/disable`; tokens are revoked). Review `audit?resource_type=patient_record` around the time. Patient notices for access events are in-app only. Legal notification duties: TBD (legal owner).

## Suspected breach
Open a `suspected_breach` incident (severity high or critical), preserve audit evidence, restrict affected facility roles (`practitioner-roles/{id}/suspend`), disable affected integration keys. Escalate to the legal/privacy owner. Do not make statements about notification duties without their review.

## Partner outage
`ops/metrics` shows `sync.failed` / `dead_letter`. Check `integrations/{id}/jobs`. Open a `partner_outage` incident. After the partner recovers, `POST sync-jobs/{id}/replay`, then ask the partner to redeliver with the same Idempotency-Key. Verify with `reconciliation`.

## Failed sync
Same as above; codes in job results are per item (`patient_unmatched`, `conflict_local_record`). Unmatched items need a patient-identity review, not a retry.

## Incorrect patient match
Open an `incorrect_patient_match` incident without identifiers in the text. Disable sharing for the affected patients (ask them to stop sharing, or suspend the facility role), review `audit`, and hand to the clinical safety owner. Merge/unmerge workflow is not built; escalate.

## Emergency-access review
Emergency (break-glass) access is not implemented. If it is added, every use must be reviewed here from `audit?...` with `emergency=true` events.