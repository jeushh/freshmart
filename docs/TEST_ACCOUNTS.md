# Local test accounts

Demo accounts are created only when `FRESHMART_SEED_DEMO=true`. All demo users
use `FRESHMART_DEMO_PASSWORD`; the administrator uses
`FRESHMART_ADMIN_PASSWORD`. The local-only default for both variables is
`test123`.

| Username | Password | Seeded role | Primary access |
|---|---|---|---|
| configured `FRESHMART_ADMIN_USERNAME` (`admin` locally) | `test123` | System Administrator | All modern modules and reports |
| `cashier` | `test123` | Cashier | POS, permitted refunds, and own cashier dashboard sales |
| `hr` | `test123` | HR Manager | Employees, attendance, HR, payroll reports |
| `finance` | `test123` | Finance Manager | Finance and authorized finance/payroll/sales reports |
| `operations` | `test123` | Operations Manager | Final restock and purchase-order approval, plus procurement/inventory reports |
| `inventory` | `test123` | Inventory Staff | Products, stock monitoring, restock requests, purchase orders, receiving, and inventory/procurement reports |
| `employee` | `test123` | Employee | Linked self-service data only |

This short password is intentionally limited to classroom demonstrations and
local development. Never use it in a shared or production environment.
Seeders require explicitly configured credentials outside local/testing, and
production should set `FRESHMART_SEED_DEMO=false`.
