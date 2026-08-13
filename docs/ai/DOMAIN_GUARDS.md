# FreshMart Domain Guards

This file records high-risk engineering boundaries.

It supplements but does not replace current code, tests,
`docs/ARCHITECTURE.md`, or `docs/SECURITY.md`.

If current repository evidence contradicts this file, stop with `HOLD` and
request reconciliation.

## Authorization

Laravel authorization is authoritative.

Vue navigation and button visibility may mirror permissions but never grant
access.

Do not create or widen backend access merely because a frontend control is
hidden.

## Cashier

Cashier responsibility is POS-centered.

Preserve these boundaries:

- POS access follows explicit permission.
- Refunds remain server-authorized.
- Cashier refund behavior remains constrained by sale ownership unless an
  explicit broader permission authorizes otherwise.
- Organization-wide sales visibility requires its explicit permission.
- Cashiers do not gain procurement or inventory-management authority to
  simplify another workflow.

## Inventory Staff

Inventory Staff perform operational inventory and procurement work.

Typical responsibilities include:

- product and stock operations
- low-stock monitoring
- inventory movements
- restock requests
- purchase-order operational work
- receiving approved deliveries

Inventory Staff do not gain final restock or purchase-order approval merely
because they created or process the operational record.

## Operations Manager

Operations Managers retain approval responsibility for restock and purchase
orders.

Approval authority must not silently expand into:

- routine stock adjustment
- receiving
- supplier-payment access
- finance mutation

## Finance

Finance workflows must not mutate inventory merely because they reference the
same purchase order.

Supplier invoice, accounts-payable, supplier-payment, and settlement details
remain behind finance authorization unless a separately approved aggregate
report intentionally exposes safe information.

Do not expose payment-level detail to Inventory Staff or Operations Managers as
a shortcut for procurement status.

## Supplier workflow compatibility

Tracked supplier workflows and historical legacy purchase orders may coexist.

Do not fabricate structured supplier invoices for historical legacy AP records.

Do not force legacy records into the newer structured workflow unless an
explicit migration or conversion design is approved.

## Supplier invoices and AP

Structured supplier-invoice behavior remains server authoritative.

Preserve these invariants:

- invoice lines belong to the purchase order
- monetary totals are server computed
- approved received coverage governs structured approval
- only Approved structured invoices create structured AP
- non-Approved states do not count as approved completion coverage
- Void does not count as approved invoice coverage

## Supplier payments

Supplier payments represent AP liability settlement.

Preserve:

- association with accounts payable
- append-only behavior unless reversal is explicitly designed later
- idempotency enforcement
- transactional balance checks
- server-authoritative AP state
- separation from inventory mutation

Do not silently add update, delete, or reversal semantics.

## Procurement close-out

Procurement close-out is computed read state.

Do not persist a manual close status merely to represent derived lifecycle
state.

Current vocabulary:

- `Open — Awaiting Delivery`
- `Open — Awaiting Invoice`
- `Open — Awaiting Payment`
- `Complete`

Payment-derived close-out information belongs only in finance-authorized read
models unless a new visibility requirement is explicitly approved.

Do not change existing receiving-only restock completion semantics to mean
financial settlement.

## Legacy and reference-only assets

The `legacy/` tree is reference-only and is not part of the modern application
runtime.

The committed `database/freshmart.sqlite` file is also a legacy/reference
database. Do not treat it as the modern runtime database and do not run Laravel
migrations against it.

The modern application database is `apps/api/database/database.sqlite`, which is
local runtime data and is ignored by Git.

Historical or legacy reference material may help explain old behavior, but
current Laravel/Vue code, tests, and canonical documentation remain the
authoritative source for modern behavior.

## Unexpected domain change

Any task requiring an unexpected change to these guards must stop with `HOLD`
before implementation continues.
