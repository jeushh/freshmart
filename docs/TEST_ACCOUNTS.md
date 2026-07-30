# Local test accounts

Demo accounts are created only when `FRESHMART_SEED_DEMO=true`. All demo users
use `FRESHMART_DEMO_PASSWORD`; the administrator uses
`FRESHMART_ADMIN_PASSWORD`.

| Username | Seeded role | Primary access |
|---|---|---|
| configured `FRESHMART_ADMIN_USERNAME` (`admin` locally) | System Administrator | All modern modules and reports |
| `cashier` | Cashier | POS and sales report |
| `hr` | HR Manager | Employees, attendance, HR, payroll reports |
| `finance` | Finance Manager | Finance and authorized finance/payroll/sales reports |
| `operations` | Operations Manager | Restock approval and procurement/inventory reports |
| `inventory` | Inventory Staff | Inventory, purchase orders, receiving, inventory/procurement reports |
| `employee` | Employee | Linked self-service data only |

The `.env.example` passwords are local-only examples. Never use them in a
shared or production environment. Seeders require explicitly configured
credentials outside local/testing, and production should set
`FRESHMART_SEED_DEMO=false`.
