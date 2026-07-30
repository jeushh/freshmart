# FreshMart Test Accounts

When `FRESHMART_SEED_DEMO=true`, the local demo accounts use the password set
in `FRESHMART_DEMO_PASSWORD`. The System Administrator uses
`FRESHMART_ADMIN_PASSWORD`.

The `.env.example` local-only default for both is:

`test123`

| Username | Password | Role |
|---|---|---|
| admin | `test123` | System Administrator |
| cashier | `test123` | Cashier |
| hr | `test123` | HR Manager |
| finance | `test123` | Finance Manager |
| operations | `test123` | Operations Manager |
| inventory | `test123` | Inventory Staff |
| employee | `test123` | Employee |

These accounts are created only by the repeatable seeders and are for local
testing and demonstrations. Set deployment-specific credentials and disable
demo seeding before any non-local deployment. Passwords are stored as hashes;
the seeders never write plaintext passwords to SQLite.
