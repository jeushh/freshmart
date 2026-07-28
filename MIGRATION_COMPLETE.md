# Vue migration completion

The Attendance, Payroll, Inventory, Finance, POS, Administration, and Employee Self-Service workspaces now use Vue screens and Laravel API routes. Legacy files remain only as a rollback reference.

## Checks performed
- PHP syntax checks for application files
- Laravel route registration
- Vue production build
- SQLite integrity check
- Authentication logout corrected to use the web guard

Use `bash scripts/setup-local.sh` once, then `bash scripts/start-local.sh`.
