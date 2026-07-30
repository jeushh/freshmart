# Security

## Authentication and authorization

The Vue client uses Laravel Sanctum’s cookie and CSRF flow. Password hashes
never appear in API responses. Login is throttled, sessions regenerate at
login, and logout invalidates the session and CSRF token.

Laravel middleware—not hidden UI controls—enforces permissions. Finance
request viewing and approval are separate. Every report has a narrow view
permission, and export requires both that view permission and
`reports.export`. Backup/restore has no web endpoint and is limited to server
operators; the backup permission is reserved for trusted system
administration visibility.

Cashier access is limited to POS and permitted refund behavior. POS sales and
dashboard refund data are scoped to the authenticated cashier unless a broader
sales-reporting permission is explicitly granted. Inventory Staff create
restock requests and perform inventory/procurement work; Operations Managers
retain final restock and purchase-order approval.

## API failures and privacy

API errors use stable codes, safe messages, validation fields, and correlation
IDs. Unexpected exceptions are logged without request payloads or secrets.
Production must use `APP_DEBUG=false`.

System settings are allowlisted. The authenticated public-settings endpoint
returns only business display, localization, tax, and operational limits. It
does not return environment variables, application keys, credentials, or
database paths.

## Data and exports

SQLite databases, WAL files, backups, and manifests must be outside the public
web root and readable only by the service/operator account. Off-host copies
should be encrypted and access logged.

CSV values beginning with formula-control characters are neutralized before
download. Users must still treat exported business, HR, payroll, and finance
data as sensitive and store it according to the report’s audience.

## Production checklist

- Replace all local credentials and disable demo seeding.
- Use HTTPS and secure cookie settings at the reverse proxy/application.
- Restrict trusted Sanctum domains and CORS origins.
- Keep PHP, Composer, Node, npm, and dependencies patched.
- Review CI audit results; network-unavailable warnings require a later
  successful advisory check.
- Grant roles the minimum report and workflow permissions needed.
- Periodically review active accounts, role changes, audit events, backups,
  restore drills, and file permissions.
