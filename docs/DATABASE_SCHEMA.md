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
| Procurement | `restock_requests`, `purchase_orders`, `purchase_order_items`, `stock_receivings`, `stock_receiving_items`, `supplier_invoices`, `supplier_invoice_items`, `accounts_payable` |
| Finance | `finance_requests`, `expenses`, `financial_transactions`, `supplier_payments` |
| Operations | `audit_logs`, `system_settings` |

Every one of the 23 application tables in the legacy database has a baseline
migration. The migrations retain the established column names, SQLite storage
types, defaults, status values, unique keys, indexes, and relationships used by
the application.

## Supplier invoice and accounts payable workflow

PR 2A adds structured supplier invoicing without rewriting historical payable
data:

- `supplier_invoices` stores one supplier invoice header associated with an
  eligible tracked purchase order. The supplier is derived from the purchase
  order rather than supplied independently by the client. Invoice states are
  `Draft`, `Registered`, `Approved`, `Disputed`, and `Void`.
- `supplier_invoice_items` stores the structured invoice lines. Every line
  references an existing `purchase_order_item`; non-purchase-order invoice
  lines are not supported. Invoiced quantity and unit cost are recorded per
  line, while line totals are calculated by the server.
- `accounts_payable.supplier_invoice_id` is a nullable foreign key to
  `supplier_invoices`. It is unique when present so one approved structured
  supplier invoice can produce at most one payable. Historical and legacy
  payable rows retain `NULL` in this column.

Accounts payable has two mutually exclusive creation paths:

1. **Legacy purchase orders** have `supplier_status IS NULL`. Stock receiving
   continues to create a `Purchase` / `Out` financial transaction and creates
   or accumulates the legacy `accounts_payable` row. Its
   `supplier_invoice_id` remains `NULL`.
2. **Tracked purchase orders** have `supplier_status IS NOT NULL`. Stock
   receiving still creates the existing `Purchase` / `Out` financial
   transaction, but it does not create or update accounts payable. A payable is
   created only when a structured supplier invoice reaches `Approved`.

Legacy purchase orders cannot enter the structured supplier-invoice workflow,
and existing historical payable records are not backfilled with fabricated
supplier invoices.

## Supplier payment settlement

PR 2B adds `supplier_payments` as an append-only settlement ledger. Each row
targets one `accounts_payable` record through the required
`accounts_payable_id` foreign key. The nullable supplier, purchase-order, and
supplier-invoice relationships are copied from the payable by the server; they
are never accepted as client-authoritative values. This supports both legacy
payables, where `supplier_invoice_id` remains `NULL`, and structured payables,
where the linked supplier invoice remains terminally `Approved`.

Payments may be partial and multiple payments may settle one payable. Monetary
decisions convert the payable total, prior paid amount, and submitted payment
to integer centavos. This prevents fractional residual balances and makes an
exact final payment transition the payable to `Paid`; an incomplete settlement
uses `Partially Paid`. Payments above the server-calculated outstanding balance
are rejected before any settlement write.

Every create request carries an `idempotency_key`. A database `UNIQUE`
constraint is the final concurrency authority: an exact replay returns the
existing payment without changing the payable or duplicating finance and audit
records, while reuse for different logical payment data is rejected.

Each new payment and the corresponding `accounts_payable.amount_paid` and
status update, `Supplier Payment` / `Out` financial transaction, and audit row
are written in one database transaction after locking the payable. Supplier
payments represent liability settlement, so reporting displays them in a
separate `supplier_payments` summary and excludes them from existing expense
and net-movement calculations. Settlement does not change inventory,
receiving, purchase-order, or supplier-invoice data. All supplier-payment
creation and history endpoints require `finance.manage`; no edit, deletion,
void, reversal, or refund operation is provided.

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
- New finalized sales snapshot columns record unit price, subtotal, tax rate,
  tax amount, tax-inclusion mode, and discount amount. Legacy rows remain null
  where the old schema did not record those facts.
- Reporting indexes cover sales dates/cashiers, refunds, purchase orders,
  receiving dates, payroll, HR requests, finance transactions, and payables.
- The reporting migration inserts only missing safe localization, tax,
  reporting-range, and backup-retention defaults so existing installations can
  migrate without running development seeders; existing setting values are
  never overwritten.
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
