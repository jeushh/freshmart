# Architecture

## Runtime

Vue SPA → Laravel JSON API → SQLite

## Rules

- Vue never writes directly to SQLite.
- Authorization is enforced in Laravel middleware, not only by hidden buttons.
- Payroll calculations are repeated on the server.
- Large lists use API pagination.
- The old application remains under `legacy/` until every workflow passes acceptance testing.

## Migration status

Migrated API foundations: authentication, dashboard, employees, attendance, payroll, products, and suppliers.
Legacy implementations remain available for finance, purchasing, stock receiving, refunds, POS checkout, and detailed reports while those modules are migrated one by one.
