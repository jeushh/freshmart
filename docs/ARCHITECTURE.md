# Architecture

## Runtime

Vue SPA → Laravel JSON API → SQLite

## Rules

- Vue never writes directly to SQLite.
- Authorization is enforced in Laravel middleware, not only by hidden buttons.
- Payroll calculations are repeated on the server.
- Large lists use API pagination.
- The old application remains under `legacy/` until every workflow passes acceptance testing.

## Application layers

- Vue owns navigation, forms, dashboards, report presentation, CSV download,
  print layouts, and recoverable error states.
- Laravel owns validation, Sanctum sessions, authorization, calculations,
  workflow transitions, correlation IDs, audit events, and JSON contracts.
- SQLite owns relational constraints and local durable data.
- Artisan owns operational backup, restore, and readiness checks. Database
  restore is intentionally not exposed over HTTP.

The modern application covers authentication, administration, POS, inventory,
procurement and receiving, HR, attendance, payroll, finance requests,
self-service, dashboards, and reports. The `legacy/` tree and
`database/freshmart.sqlite` remain reference-only and are not application
runtime dependencies.

## Reporting flow

Report routes have a literal report type and a dedicated view permission.
Laravel validates all filters, applies server-side summaries and pagination,
and uses the same query definition for CSV rows. Export additionally requires
`reports.export`. The Vue interface only shows reports granted to the current
role.

## Configuration and historical truth

Only keys in `SystemSettingCatalog` are editable. A cached safe subset is
returned to authenticated clients. New POS ledger rows snapshot unit price,
subtotal, tax rate, tax amount, inclusion mode, discount, and gross total.
Changing current tax settings does not rewrite finalized sales.
