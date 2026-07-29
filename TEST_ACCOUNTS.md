# FreshMart Test Accounts

When `FRESHMART_SEED_DEMO=true`, the local demo accounts use the password set
in `FRESHMART_DEMO_PASSWORD`. The System Administrator uses
`FRESHMART_ADMIN_PASSWORD`.

The `.env.example` local-only default for both is:

`FreshMart-Local-Only-2026!`

| Username | Role |
|---|---|
| admin | System Administrator |
| cashier | Cashier |
| hr | HR Manager |
| finance | Finance Manager |
| operations | Operations Manager |
| inventory | Inventory Staff |
| employee | Employee |

These accounts are created only by the repeatable seeders and are for local
testing and demonstrations. Set deployment-specific credentials and disable
demo seeding before any non-local deployment. Passwords are stored as hashes;
the seeders never write plaintext passwords to SQLite.
