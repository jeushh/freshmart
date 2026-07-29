# Database schema

The Laravel baseline was derived from the committed
`database/freshmart.sqlite` schema and cross-checked against the Laravel
controllers, models, middleware, routes, tests, frontend API fields, setup
scripts, and legacy query inventory.

## Application tables

| Area | Tables |
|---|---|
| Access and people | `roles`, `admin_users`, `employees` |
| Catalog | `suppliers`, `products` |
| HR and payroll | `attendance_logs`, `hr_requests`, `payroll` |
| Sales and inventory | `sales_ledger`, `refunds`, `inventory_movements`, `cash_drawers` |
| Procurement | `restock_requests`, `purchase_orders`, `purchase_order_items`, `stock_receivings`, `stock_receiving_items`, `accounts_payable` |
| Finance | `finance_requests`, `expenses`, `financial_transactions` |
| Operations | `audit_logs`, `system_settings` |

Every one of the 23 application tables in the legacy database has a baseline
migration. The migrations retain the established column names, SQLite storage
types, defaults, status values, unique keys, indexes, and relationships used by
the application.

## Laravel support tables

The baseline also creates:

- `migrations`, managed by Laravel itself;
- `sessions`, because the session configuration supports the database driver;
- `password_reset_tokens`, because the authentication provider is configured
  for password resets; and
- `personal_access_tokens`, because the authenticated user model uses Sanctum.

Cache and queue tables are intentionally omitted. The supported defaults are
file cache and synchronous queues, so those tables are not required.

The legacy `login_attempts` helper table is also omitted. It is absent from the
committed reference database and Laravel applies login throttling through its
route middleware.

## Intentional differences from the legacy schema

The rebuilt database is structurally equivalent for application fields, with
these deliberate integrity improvements:

- `admin_users.employee_id` now has both a foreign key and a unique constraint,
  matching the one-account-per-employee validation rule.
- `products.supplier_id` now has a foreign key and index.
- `restock_requests.purchase_order_id` now has a foreign key.
- `payroll` now has a unique key on employee and pay-period dates, matching
  payroll duplicate validation.
- Non-negative amounts, stock, hours, and quantities enforced by application
  validation now also have SQLite `CHECK` constraints.
- Product and employee status/pay-type fields now have database-level checks.
- Query-backed employee, product supplier, finance request, HR request, and
  financial transaction reference indexes were added.
- Laravel support tables are new; they were not part of the legacy database.

No application table or column from the committed reference schema was
silently omitted.

## Seed-data differences

Seeders rebuild a small, deterministic development dataset rather than copying
historical operations:

- the legacy-only `test` role and `test` user are not seeded;
- eight representative products replace the 29 historical product rows;
- three clean demo employees and suppliers are seeded;
- pending demo HR/finance requests and one draft payroll record are created;
  and
- historical sales, purchasing, receiving, payable, audit, and payroll records
  are not imported.

In particular, the historical payroll rows in
`database/freshmart.sqlite` were not modified. The reference database itself is
not opened by migrations, seeders, setup scripts, or PHPUnit.
