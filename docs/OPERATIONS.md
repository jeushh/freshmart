# Operations

## Routine checks

Run `php artisan freshmart:health` after deployment and through external
monitoring. It checks production debug/demo risk, SQLite integrity and foreign
keys, pending migrations, database and storage writability, settings, backup
directory readiness, and queue configuration. Critical failures return a
non-zero exit code.

The HTTP `/up` endpoint is a lightweight process check. It does not replace the
deeper command-line health check.

## Backups

Schedule `php artisan freshmart:backup`, monitor its exit code, and copy the
snapshot and manifest together to protected off-host storage. Alert when:

- a scheduled run is missing or non-zero;
- the newest backup is older than policy permits;
- a checksum differs from its manifest; or
- a restore drill fails.

## Logs and incidents

Every API request receives an `X-Request-ID`. Error JSON also returns
`request_id`, and unexpected failures are logged with that identifier, path,
exception class, and authenticated user ID. Ask users for the displayed
reference when investigating failures.

Logs intentionally exclude request bodies and credentials. Restrict log access
and rotate according to policy. The production-ready example uses
`LOG_STACK=daily`; `LOG_DAILY_DAYS` controls on-host retention. Ship logs to
protected centralized storage when incident-retention requirements exceed
the local window.

For an incident:

1. preserve logs, correlation IDs, the current database, and the latest
   verified backup;
2. stop writers if data integrity may be affected;
3. run health/integrity checks against a copy;
4. identify the narrow workflow and affected records;
5. restore only when repair or application rollback is insufficient; and
6. document verification before reopening writes.

## Capacity

Reports cap page size and CSV rows, but broad date ranges still consume local
CPU and I/O. Tune `report_max_date_range_days`, monitor database size and
request duration, and move to a server database before multiple application
hosts or sustained concurrent writes are required.
