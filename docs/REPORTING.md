# Reporting

## Report access

Each report requires its own permission:

| Report | Permission |
|---|---|
| Sales | `reports.sales.view` |
| Inventory | `reports.inventory.view` |
| Procurement | `reports.procurement.view` |
| HR | `reports.hr.view` |
| Payroll | `reports.payroll.view` |
| Finance | `reports.finance.view` |

CSV download also requires `reports.export`. That permission never grants
access to a report by itself. The route and server query enforce access even if
a user manually constructs a URL.

## Filters and calculations

Every report accepts an inclusive `from` and `to` date. The configured
`report_max_date_range_days` limits expensive ranges, and page size is capped
at 100.

- Sales filters cashier, payment method, finalized/refunded state, product,
  and category. It reports gross and net sales, refunds, transaction count,
  average transaction, stored tax, discounts, and units.
- Inventory reports current quantities, reorder/max states, cost and retail
  valuation, plus date-ranged movement count, stock-in, stock-out, and latest
  movement. Movement-type, category, supplier, and stock-state filters apply.
- Procurement reports PO approval/operational state and explicitly separates
  ordered, delivered, accepted, fulfilled, damaged, rejected, and outstanding
  quantities. Current policy treats accepted quantity as fulfilled quantity.
- HR reports employee status, date-ranged attendance, late/absent counts,
  approved leave use, current leave balance, and pending requests.
- Payroll reports pay periods, employee/department/status, gross pay,
  deductions, and net pay.
- Finance combines posted financial transactions and outstanding accounts
  payable, with revenue, expense, and net cash movement summaries.

Accounts receivable is not represented by the current schema. The finance
report returns it as unsupported and does not manufacture a balance.

## Historical values

New sales snapshot the tax calculation at checkout. Historical rows from
before this feature retain stored totals, while tax and discount fields remain
null if the old ledger did not record them. Reports never recalculate old
transactions using today’s tax setting.

Inventory valuation is intentionally current-state reporting: it multiplies
current on-hand stock by current cost and retail prices. This differs from
historical transaction-cost reporting and is labeled in the report notes.

## Export and printing

CSV export uses the same validated filters and query as the visible report and
is capped at 10,000 rows per download. Cells beginning with spreadsheet
formula characters are prefixed safely. The file is UTF-8 with a byte-order
mark for common spreadsheet applications.

The Reports page has a print layout that removes navigation and filters,
retains the business name, generation time, applied filters, summary, records,
and report notes. Browser print-to-PDF is supported.
