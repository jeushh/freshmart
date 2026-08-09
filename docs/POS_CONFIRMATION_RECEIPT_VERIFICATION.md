# POS confirmation and receipt manual verification

Use a disposable seeded SQLite database. Do not run these scenarios against a working production or demonstration database because they intentionally complete and fail sales.

## Setup

1. Start the Laravel API and Vue application against the disposable database.
2. Sign in as the seeded Cashier account.
3. Open browser developer tools, preserve the Network log, and filter requests to `workspace/pos/checkout`.
4. Keep the database available for read-only checks of `sales_ledger`, `inventory_movements`, `financial_transactions`, and `audit_logs`.

## 1. Cancel preserves the cart

1. Add two different products and increase one product to quantity two.
2. Record the item names, quantities, subtotal, tax, total, and payment method.
3. Select **Review sale**.
4. Confirm the dialog shows the same cart contents and computed totals.
5. Select **Cancel**.

Expected:

- No checkout request appears in the Network log.
- Focus returns to **Review sale**.
- Every cart item and quantity is unchanged.
- Subtotal, tax, total, and payment method are unchanged.

## 2. Confirm submits exactly once

1. Open the confirmation dialog for a non-empty cart.
2. Select **Complete sale** once.

Expected:

- Exactly one checkout request appears.
- The confirmation action enters a loading/disabled state.
- One receipt appears after the successful response.
- Only one order ID is returned and only that order is inserted in the sale-related tables.

## 3. Rapid double-click cannot duplicate the sale

1. Add an item and open confirmation.
2. Enable network throttling so the checkout response remains pending briefly.
3. Rapidly click **Complete sale** twice.

Expected:

- Exactly one checkout request appears.
- The second interaction cannot submit while the first request is pending.
- One order ID and one set of sale effects are created.

## 4. Failed checkout keeps a recoverable cart and shows no receipt

1. Add a product to the cart and open confirmation.
2. In a separate authenticated session or directly in the disposable database, reduce that product's available stock below the cart quantity before confirming.
3. Select **Complete sale**.

Expected:

- The API returns its existing validation error.
- The error remains visible in the confirmation dialog.
- No success receipt is shown.
- Canceling returns to the unchanged cart so its quantity can be corrected.
- No partial sale, stock movement, finance transaction, or sale audit entry is committed.

## 5. Receipt uses the server response

1. Complete a successful sale with developer tools open.
2. Inspect the checkout JSON response.
3. Compare its `order_id`, `completed_at`, `cashier_username`, `payment_method`, `items`, `subtotal`, `tax_total`, `tax_rate`, `tax_inclusive`, and `total` with the rendered receipt.
4. Resize the browser or trigger another Vue re-render without reloading the page.

Expected:

- Every finalized receipt value matches the response exactly.
- Receipt values do not change after the cart is cleared or the component re-renders.
- No receipt value is taken from the now-empty cart.

## 6. Cash payment has no invented tender fields

1. Select **Cash** and complete a sale.

Expected:

- The confirmation and receipt show `Cash` as the payment method.
- Neither view shows amount tendered or change.
- No new cash-validation behavior appears.

## 7. Printing never resubmits checkout

1. On a completed receipt, record the checkout-request count.
2. Select **Print receipt** and inspect the print preview.
3. Cancel or finish printing, then repeat once.

Expected:

- The checkout-request count does not change.
- Print preview contains only the receipt in black on white.
- Sidebar, header, navigation, page actions, backgrounds, and shadows are absent.
- Receipt content fits a narrow thermal-style layout.

## 8. New sale returns to a clean ready state

1. On a completed receipt, select **New sale**.

Expected:

- Receipt presentation closes.
- The active cart is empty.
- Payment method is reset to `Cash`.
- Product selection is available for a new sale.
- No checkout request occurs and the completed transaction is not modified.

## Responsive and keyboard checks

Repeat the confirmation and receipt checks at approximately 375px, 768px, 1024px, and 1440px.

Expected at every width:

- Dialog and receipt remain within the viewport without horizontal page overflow.
- Long product names, SKUs, order IDs, and totals remain readable.
- Initial dialog focus lands on **Cancel**.
- Tab and Shift+Tab remain inside the dialog.
- Escape cancels confirmation and restores focus to **Review sale**.
- Backdrop click cancels confirmation without changing the cart.
- Visible focus indicators remain present.
