<?php
/**
 * =====================================================================================
 * backend/db.php — DATA ACCESS LAYER
 * =====================================================================================
 * Responsibility: own the ONLY PDO connection to the SQLite datastore, create the
 * schema on first run, and seed baseline product data. No other file talks to SQLite
 * directly without going through this connection — products.php and event_bus.php
 * both `require_once` this file rather than opening their own connections.
 *
 * This is the boundary that lets us swap SQLite for MySQL/Postgres later by editing
 * ONLY this file — nothing in products.php or event_bus.php needs to change, because
 * they only ever call standard PDO methods against $pdo.
 * =====================================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$dbFile = databasePath();
$isNewDatabase = !file_exists($dbFile);

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL'); // better concurrent read/write behaviour for a local file DB
    $pdo->exec('PRAGMA busy_timeout = 5000');
} catch (PDOException $e) {
    error_log('db.php connection: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['ok' => false, 'error' => 'Database connection failed.']));
}

if ($isNewDatabase) {
    initializeSchema($pdo);
    if (demoSeedingEnabled()) {
        seedProducts($pdo);
        seedRolesAndUsers($pdo);
    }
    migrateInventoryFinanceModules($pdo);
} else {
    // Migrate: create purchase_orders if it was added after this DB was first seeded
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS purchase_orders (
            id                     INTEGER PRIMARY KEY AUTOINCREMENT,
            sku                    TEXT    NOT NULL,
            quantity_ordered       INTEGER NOT NULL CHECK (quantity_ordered > 0),
            status                 TEXT    NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending', 'Received', 'Cancelled')),
            triggered_by_order_id  TEXT,
            created_at             TEXT    NOT NULL DEFAULT (datetime('now')),
            received_at            TEXT
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_po_sku_status ON purchase_orders(sku, status)');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL UNIQUE,
            description TEXT,
            permissions TEXT NOT NULL DEFAULT '[]',
            created_at  TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            full_name     TEXT NOT NULL,
            role_id       INTEGER NOT NULL REFERENCES roles(id),
            employee_id   INTEGER,
            status        TEXT NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Disabled')),
            created_at    TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    // roles.landing_page: which page a role's members land on after login. Older
    // DBs (pre-dating this column) get it added here; ensureColumn() reports back
    // whether it just created the column so we only run the one-time Administrator
    // fixup below on that first migration, not on every subsequent request.
    $addedLandingPage = ensureColumn($pdo, 'roles', 'landing_page', "TEXT NOT NULL DEFAULT 'pos'");
    if ($addedLandingPage) {
        $pdo->exec("UPDATE roles SET landing_page = 'admin' WHERE name = 'Administrator'");
    }
    // sales_ledger.cashier_username: attributes each sale to the logged-in user who
    // rang it up (see event_bus.php, which now requires an authenticated session).
    ensureColumn($pdo, 'sales_ledger', 'cashier_username', 'TEXT');
    // Senior Citizen / PWD discount (RA 9994 / RA 10754): 20% off + VAT-exempt.
    ensureColumn($pdo, 'sales_ledger', 'discount_type', 'TEXT');
    ensureColumn($pdo, 'sales_ledger', 'discount_id_number', 'TEXT');
    // Leave balance in days. Must run BEFORE seedAdditionalRoles() below, since
    // seedEmployeeSelfServiceDemo() (called from within it) inserts a demo
    // employee row that includes this column — inserting into a column that
    // doesn't exist yet throws, and since that throw happens before this
    // ensureColumn() would otherwise run, the column would never get added and
    // every single request would keep failing the same way forever.
    ensureColumn($pdo, 'employees', 'leave_balance', 'REAL NOT NULL DEFAULT 15');
    // Seed default admin user if none exists
    $existing = $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ((int)$existing === 0) {
        seedRolesAndUsers($pdo);
    }
    if (demoSeedingEnabled()) { seedAdditionalRoles($pdo); }
    // Rename in place (not a new INSERT) so the existing admin_users.role_id FK
    // link and role id stay intact — naturally idempotent, since after the first
    // run no row is named 'Administrator' anymore and the WHERE clause matches nothing.
    $pdo->exec("UPDATE roles SET name = 'System Administrator' WHERE name = 'Administrator'");
    ensureSystemAdminHasBaselinePermissions($pdo);
    // Password change: 'freshmart-admin' -> 'admin123'. Only overwrites if the
    // stored hash still matches the OLD default — if an admin already changed
    // this password by hand, that custom password is left untouched, same
    // "never clobber a manual change" principle as ensureRoleHasPermissions().
    $pdo->prepare("UPDATE admin_users SET password_hash = :new WHERE username = 'admin' AND password_hash = :old")
        ->execute([':new' => password_hash('admin123', PASSWORD_DEFAULT), ':old' => hash('sha256', 'freshmart-admin')]);
    // Inventory / Restock / Purchasing / Finance / Payroll / Audit extension —
    // adds new tables and columns without touching any existing data. Safe to
    // call on every request (every statement inside is idempotent).
    migrateInventoryFinanceModules($pdo);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employees (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_no       TEXT NOT NULL UNIQUE,
            full_name         TEXT NOT NULL,
            position          TEXT NOT NULL,
            department        TEXT NOT NULL,
            email             TEXT,
            phone             TEXT,
            hire_date         TEXT NOT NULL,
            employment_status TEXT NOT NULL DEFAULT 'Active' CHECK (employment_status IN ('Active','On Leave','Terminated')),
            created_at        TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_employees_name ON employees(full_name)");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hr_requests (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id  INTEGER NOT NULL REFERENCES employees(id),
            request_type TEXT    NOT NULL CHECK (request_type IN ('Leave','Overtime','Other')),
            start_date   TEXT,
            end_date     TEXT,
            hours        REAL,
            reason       TEXT    NOT NULL,
            status       TEXT    NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending','Approved','Rejected')),
            reviewed_by  INTEGER REFERENCES admin_users(id),
            reviewed_at  TEXT,
            review_notes TEXT,
            created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hr_requests_status ON hr_requests(status)");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attendance_logs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL REFERENCES employees(id),
            log_date    TEXT NOT NULL,
            time_in     TEXT,
            time_out    TEXT,
            status      TEXT NOT NULL DEFAULT 'Present' CHECK (status IN ('Present','Late','Absent','On Leave')),
            notes       TEXT,
            UNIQUE (employee_id, log_date)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attendance_employee_date ON attendance_logs(employee_id, log_date)");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS finance_requests (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id  INTEGER NOT NULL REFERENCES employees(id),
            request_type TEXT    NOT NULL CHECK (request_type IN ('Reimbursement','Purchase')),
            amount       REAL    NOT NULL CHECK (amount >= 0),
            category     TEXT,
            description  TEXT    NOT NULL,
            status       TEXT    NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending','Approved','Rejected','Paid')),
            reviewed_by  INTEGER REFERENCES admin_users(id),
            reviewed_at  TEXT,
            review_notes TEXT,
            created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_finance_requests_status ON finance_requests(status)");

    // Refunds: append-only, mirrors sales_ledger's write style. A refund never
    // edits or deletes the original sale — it's a separate compensating record,
    // same principle as accounting reversal entries never touching the original.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS refunds (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id          TEXT    NOT NULL,
            item_sku          TEXT    NOT NULL,
            quantity_refunded INTEGER NOT NULL CHECK (quantity_refunded > 0),
            refund_amount     REAL    NOT NULL CHECK (refund_amount >= 0),
            reason            TEXT,
            processed_by      TEXT,
            created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_refunds_order ON refunds(order_id)");
}

/**
 * Seeds two new demo roles/logins so the restock-approval workflow (Manager) and
 * the day-to-day inventory workflow (Inventory Staff) both have someone to log in
 * as, mirroring the pattern seedAdditionalRoles() already established for Cashier /
 * HR Manager / Finance Manager. INSERT OR IGNORE throughout — safe on every request.
 */
function seedInventoryAndOperationsRoles(PDO $pdo): void
{
    $roleDefs = [
        [
            'name'         => 'Operations Manager',
            'description'  => 'Approves restock requests and oversees inventory + sales reporting.',
            'permissions'  => ['restock.approve', 'sales.view'],
            'landing_page' => 'inventory',
            'username'     => 'ops.manager',
            'password'     => 'ops123',
            'full_name'    => 'Demo Operations Manager',
        ],
        [
            'name'         => 'Inventory Staff',
            'description'  => 'Manages products, suppliers, restock requests, purchase orders, and stock receiving.',
            'permissions'  => ['inventory.manage', 'restock.request'],
            'landing_page' => 'inventory',
            'username'     => 'inventory.staff',
            'password'     => 'inventory123',
            'full_name'    => 'Demo Inventory Staff',
        ],
    ];

    foreach ($roleDefs as $def) {
        $pdo->prepare(
            "INSERT OR IGNORE INTO roles (name, description, permissions, landing_page)
             VALUES (:name, :desc, :perms, :landing)"
        )->execute([
            ':name'    => $def['name'],
            ':desc'    => $def['description'],
            ':perms'   => json_encode($def['permissions']),
            ':landing' => $def['landing_page'],
        ]);
        $roleId = $pdo->query(
            'SELECT id FROM roles WHERE name = ' . $pdo->quote($def['name'])
        )->fetchColumn();

        $pdo->prepare(
            "INSERT OR IGNORE INTO admin_users (username, password_hash, full_name, role_id)
             VALUES (:username, :hash, :full_name, :role)"
        )->execute([
            ':username'  => $def['username'],
            ':hash'      => password_hash($def['password'], PASSWORD_DEFAULT),
            ':full_name' => $def['full_name'],
            ':role'      => $roleId,
        ]);
    }
}

/**
 * Seeds a handful of demo suppliers (once — INSERT OR IGNORE-style guarded by an
 * empty-table check) and backfills sensible cost_price/max_stock/supplier_id
 * defaults onto any product row that still has the as-added defaults (cost_price=0),
 * so the Inventory/Restock/PO screens have believable numbers out of the box
 * without ever overwriting a value an admin has since edited.
 */
function seedSuppliersAndProductDefaults(PDO $pdo): void
{
    $supplierCount = (int) $pdo->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
    if ($supplierCount === 0) {
        $suppliers = [
            ['Manila Fresh Produce Co.',      'Ana Reyes',     '0917-100-2001', 'sales@manilafresh.ph',   'Divisoria, Manila'],
            ['Luzon Dairy & Bakery Supply',   'Ben Santos',    '0917-100-2002', 'orders@luzondairy.ph',   'Malolos, Bulacan'],
            ['Southern Beverages Distribution','Carla Cruz',   '0917-100-2003', 'carla@southernbev.ph',   'Calamba, Laguna'],
            ['Golden Snacks Wholesale',       'Dennis Uy',     '0917-100-2004', 'dennis@goldensnacks.ph', 'Quezon City'],
            ['Central Vegetable Traders',     'Elena Bautista','0917-100-2005', 'elena@centralveg.ph',    'Nueva Ecija'],
        ];
        $ins = $pdo->prepare(
            'INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($suppliers as $s) {
            $ins->execute($s);
        }
    }

    $supplierIds = array_column($pdo->query('SELECT id, name FROM suppliers ORDER BY id')->fetchAll(), 'id', 'name');
    $categoryToSupplier = [
        'Fruits'     => $supplierIds['Central Vegetable Traders'] ?? null,
        'Vegetables' => $supplierIds['Central Vegetable Traders'] ?? null,
        'Dairy'      => $supplierIds['Luzon Dairy & Bakery Supply'] ?? null,
        'Bakery'     => $supplierIds['Luzon Dairy & Bakery Supply'] ?? null,
        'Beverages'  => $supplierIds['Southern Beverages Distribution'] ?? null,
        'Snacks'     => $supplierIds['Golden Snacks Wholesale'] ?? null,
    ];

    $products = $pdo->query('SELECT id, category, price, stock_quantity, cost_price, max_stock, supplier_id FROM products')->fetchAll();
    $update = $pdo->prepare('UPDATE products SET cost_price = :cost, max_stock = :max, supplier_id = :sup WHERE id = :id');
    foreach ($products as $p) {
        // Only backfill rows that still look untouched (cost_price never set).
        if ((float) $p['cost_price'] > 0) {
            continue;
        }
        $cost = round(((float) $p['price']) * 0.65, 2); // assume ~35% margin
        $max  = max(50, ((int) $p['stock_quantity']) * 3);
        $sup  = $p['supplier_id'] ?: ($categoryToSupplier[$p['category']] ?? null);
        $update->execute([':cost' => $cost, ':max' => $max, ':sup' => $sup, ':id' => $p['id']]);
    }
}

/**
 * One-time structural rebuild of purchase_orders. The table originally shipped with
 * CHECK (status IN ('Pending','Received','Cancelled')) and NOT NULL sku/quantity_ordered
 * (one-SKU-per-PO). SQLite cannot ALTER a CHECK constraint or drop NOT NULL in place,
 * so we detect the old shape (absence of the `po_number` column) and rebuild:
 *   1. Create purchase_orders_new with the wider status vocabulary + new columns.
 *   2. Copy every existing row across (Pending->Pending, Received->Fully Received,
 *      Cancelled->Cancelled), preserving id, created_at, received_at.
 *   3. Backfill a matching purchase_order_items row for each legacy PO so old data
 *      is still visible through the new items-based UI.
 *   4. Drop the old table and rename the new one into place.
 * Skipped entirely (no-op) once `po_number` already exists — i.e. every request
 * after the first migration.
 */
function rebuildPurchaseOrdersTableIfNeeded(PDO $pdo): void
{
    $existingColumns = array_column($pdo->query("PRAGMA table_info(purchase_orders)")->fetchAll(), 'name');
    if (in_array('po_number', $existingColumns, true)) {
        return; // already migrated
    }

    // Defensive: if an earlier run of this function crashed partway through
    // (before the fix that made this whole rebuild transactional), a stale
    // purchase_orders_new table could be left lying around. Clear it so this
    // rebuild can always start from a clean slate no matter what state a
    // given database file is in.
    $pdo->exec('DROP TABLE IF EXISTS purchase_orders_new');

    $pdo->beginTransaction();
    try {
        $pdo->exec("
            CREATE TABLE purchase_orders_new (
                id                      INTEGER PRIMARY KEY AUTOINCREMENT,
                po_number               TEXT UNIQUE,
                restock_request_id      INTEGER REFERENCES restock_requests(id),
                supplier_id             INTEGER REFERENCES suppliers(id),
                sku                     TEXT,
                quantity_ordered        INTEGER,
                order_date              TEXT NOT NULL DEFAULT (datetime('now')),
                expected_delivery_date  TEXT,
                notes                   TEXT,
                status                  TEXT NOT NULL DEFAULT 'Pending' CHECK (status IN (
                                          'Pending','Approved','Ordered','Partially Received','Fully Received','Cancelled')),
                triggered_by_order_id   TEXT,
                created_at              TEXT NOT NULL DEFAULT (datetime('now')),
                received_at             TEXT
            )
        ");

        $existing = $pdo->query('SELECT * FROM purchase_orders')->fetchAll();
        $statusMap = ['Pending' => 'Pending', 'Received' => 'Fully Received', 'Cancelled' => 'Cancelled'];

        $insertPo = $pdo->prepare(
            "INSERT INTO purchase_orders_new
                (id, po_number, sku, quantity_ordered, order_date, status, triggered_by_order_id, created_at, received_at)
             VALUES (:id, :po_number, :sku, :qty, :order_date, :status, :trig, :created, :received)"
        );
        // Safe now: purchase_order_items is guaranteed to already exist, since
        // migrateInventoryFinanceModules() creates it immediately before calling
        // this function.
        $insertItem = $pdo->prepare(
            "INSERT INTO purchase_order_items (purchase_order_id, sku, quantity_ordered, quantity_received, unit_cost)
             VALUES (:po_id, :sku, :qty, :received, 0)"
        );

        foreach ($existing as $row) {
            $newStatus = $statusMap[$row['status']] ?? 'Pending';
            $insertPo->execute([
                ':id'          => (int) $row['id'],
                ':po_number'   => 'PO-LEGACY-' . str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT),
                ':sku'         => $row['sku'],
                ':qty'         => (int) $row['quantity_ordered'],
                ':order_date'  => $row['created_at'],
                ':status'      => $newStatus,
                ':trig'        => $row['triggered_by_order_id'],
                ':created'     => $row['created_at'],
                ':received'    => $row['received_at'],
            ]);
        }

        $pdo->exec('DROP TABLE purchase_orders');
        $pdo->exec('ALTER TABLE purchase_orders_new RENAME TO purchase_orders');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_po_sku_status ON purchase_orders(sku, status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_po_restock ON purchase_orders(restock_request_id)');

        // Backfill purchase_order_items for legacy rows now that purchase_orders (and
        // therefore its ids) exist again post-rename. Must run AFTER the rename since
        // purchase_order_items.purchase_order_id logically references the final table.
        foreach ($existing as $row) {
            $receivedQty = $row['status'] === 'Received' ? (int) $row['quantity_ordered'] : 0;
            $insertItem->execute([
                ':po_id'    => (int) $row['id'],
                ':sku'      => $row['sku'],
                ':qty'      => (int) $row['quantity_ordered'],
                ':received' => $receivedQty,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Existing System Administrator rows (seeded before a new permission key was
 * added to the catalog — e.g. pos.access) won't pick it up automatically, since
 * seedRolesAndUsers()/seedAdditionalRoles() use INSERT OR IGNORE and never
 * touch a role that already exists. This adds any missing baseline keys to the
 * role's permission set without removing anything already there, so a custom
 * permission tweak made via Roles & Users survives untouched.
 */
function ensureSystemAdminHasBaselinePermissions(PDO $pdo): void
{
    $baseline = [
        'system.roles.manage',
        'hr.employees.view', 'hr.employees.edit',
        'hr.attendance.view', 'hr.attendance.edit',
        'hr.requests.view', 'hr.requests.approve',
        'finance.requests.view', 'finance.requests.approve',
        'inventory.manage',
        'sales.view',
        'pos.access',
        'pos.refund',
        'employee.self',
    ];

    $stmt = $pdo->prepare('SELECT permissions FROM roles WHERE name = ?');
    $stmt->execute(['System Administrator']);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        return; // no System Administrator role at all yet — seedRolesAndUsers() handles that case
    }

    $currentPerms = json_decode((string)$current, true) ?? [];
    $merged = array_values(array_unique(array_merge($currentPerms, $baseline)));

    sort($currentPerms);
    $sortedMerged = $merged;
    sort($sortedMerged);
    if ($currentPerms === $sortedMerged) {
        return; // already has everything — no write needed
    }

    $pdo->prepare('UPDATE roles SET permissions = ? WHERE name = ?')
        ->execute([json_encode($merged), 'System Administrator']);
}

/**
 * Creates the tables required by the system:
 *  - products: the live inventory catalogue (mirrors an IMS product table)
 *  - sales_ledger: an append-only transaction log (mirrors an ERP/accounting journal)
 *  - purchase_orders: auto-generated reorder POs triggered by low-stock checkout events
 */
function initializeSchema(PDO $pdo): void
{
    // NOTE: Existing freshmart.db files must be deleted and regenerated to pick up
    // the CHECK constraints below — SQLite does not alter table constraints in place.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            name            TEXT    NOT NULL,
            sku             TEXT    NOT NULL UNIQUE,
            price           REAL    NOT NULL CHECK (price >= 0),
            category        TEXT    NOT NULL,
            stock_quantity  INTEGER NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
            unit            TEXT    NOT NULL DEFAULT 'pc',
            emoji           TEXT    NOT NULL DEFAULT '🛒'
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sales_ledger (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id            TEXT    NOT NULL,
            item_sku            TEXT    NOT NULL,
            quantity_sold       INTEGER NOT NULL,
            total_price         REAL    NOT NULL,
            payment_method      TEXT    NOT NULL DEFAULT 'Cash',
            cashier_username    TEXT,
            discount_type       TEXT,
            discount_id_number  TEXT,
            timestamp           TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS purchase_orders (
            id                     INTEGER PRIMARY KEY AUTOINCREMENT,
            sku                    TEXT    NOT NULL,
            quantity_ordered       INTEGER NOT NULL CHECK (quantity_ordered > 0),
            status                 TEXT    NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending', 'Received', 'Cancelled')),
            triggered_by_order_id  TEXT,
            created_at             TEXT    NOT NULL DEFAULT (datetime('now')),
            received_at            TEXT
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT NOT NULL UNIQUE,
            description   TEXT,
            permissions   TEXT NOT NULL DEFAULT '[]',
            landing_page  TEXT NOT NULL DEFAULT 'pos' CHECK (landing_page IN ('pos','hr','finance','admin','employee')),
            created_at    TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            full_name     TEXT NOT NULL,
            role_id       INTEGER NOT NULL REFERENCES roles(id),
            employee_id   INTEGER,
            status        TEXT NOT NULL DEFAULT 'Active' CHECK (status IN ('Active','Disabled')),
            created_at    TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employees (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_no       TEXT NOT NULL UNIQUE,
            full_name         TEXT NOT NULL,
            position          TEXT NOT NULL,
            department        TEXT NOT NULL,
            email             TEXT,
            phone             TEXT,
            hire_date         TEXT NOT NULL,
            employment_status TEXT NOT NULL DEFAULT 'Active' CHECK (employment_status IN ('Active','On Leave','Terminated')),
            leave_balance     REAL NOT NULL DEFAULT 15,
            created_at        TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hr_requests (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id  INTEGER NOT NULL REFERENCES employees(id),
            request_type TEXT    NOT NULL CHECK (request_type IN ('Leave','Overtime','Other')),
            start_date   TEXT,
            end_date     TEXT,
            hours        REAL,
            reason       TEXT    NOT NULL,
            status       TEXT    NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending','Approved','Rejected')),
            reviewed_by  INTEGER REFERENCES admin_users(id),
            reviewed_at  TEXT,
            review_notes TEXT,
            created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attendance_logs (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL REFERENCES employees(id),
            log_date    TEXT NOT NULL,
            time_in     TEXT,
            time_out    TEXT,
            status      TEXT NOT NULL DEFAULT 'Present' CHECK (status IN ('Present','Late','Absent','On Leave')),
            notes       TEXT,
            UNIQUE (employee_id, log_date)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS finance_requests (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id  INTEGER NOT NULL REFERENCES employees(id),
            request_type TEXT    NOT NULL CHECK (request_type IN ('Reimbursement','Purchase')),
            amount       REAL    NOT NULL CHECK (amount >= 0),
            category     TEXT,
            description  TEXT    NOT NULL,
            status       TEXT    NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending','Approved','Rejected','Paid')),
            reviewed_by  INTEGER REFERENCES admin_users(id),
            reviewed_at  TEXT,
            review_notes TEXT,
            created_at   TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS refunds (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id          TEXT    NOT NULL,
            item_sku          TEXT    NOT NULL,
            quantity_refunded INTEGER NOT NULL CHECK (quantity_refunded > 0),
            refund_amount     REAL    NOT NULL CHECK (refund_amount >= 0),
            reason            TEXT,
            processed_by      TEXT,
            created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_products_category ON products(category)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ledger_order ON sales_ledger(order_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_po_sku_status ON purchase_orders(sku, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_employees_name ON employees(full_name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hr_requests_status ON hr_requests(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_attendance_employee_date ON attendance_logs(employee_id, log_date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_finance_requests_status ON finance_requests(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_refunds_order ON refunds(order_id)");
}

function seedRolesAndUsers(PDO $pdo): void
{
    $permissions = json_encode([
        'system.roles.manage',
        'hr.employees.view', 'hr.employees.edit',
        'hr.attendance.view', 'hr.attendance.edit',
        'hr.requests.view', 'hr.requests.approve',
        'finance.requests.view', 'finance.requests.approve',
        'inventory.manage',
        'sales.view',
        'pos.access',
        'pos.refund',
        'employee.self',
    ]);
    $pdo->prepare(
        "INSERT OR IGNORE INTO roles (name, description, permissions, landing_page)
         VALUES ('System Administrator', 'Full system access', :perms, 'admin')"
    )->execute([':perms' => $permissions]);
    $roleId = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM roles WHERE name='System Administrator'")->fetchColumn();
    $pdo->prepare(
        "INSERT OR IGNORE INTO admin_users (username, password_hash, full_name, role_id) VALUES ('admin', :hash, 'System Administrator', :role)"
    )->execute([':hash' => password_hash('admin123', PASSWORD_DEFAULT), ':role' => $roleId]);

    if (demoSeedingEnabled()) { seedAdditionalRoles($pdo); }
}

/**
 * Seeds the Cashier / HR Manager / Finance Manager roles (and one demo login for
 * each) so the role-based landing-page routing has more than just System
 * Administrator to demonstrate. Uses INSERT OR IGNORE throughout, so it's safe
 * to call on every request — it only ever fills in rows that don't already
 * exist by name/username.
 */
function seedAdditionalRoles(PDO $pdo): void
{
    $roleDefs = [
        [
            'name'         => 'Cashier',
            'description'  => 'Point-of-sale access, including processing refunds.',
            'permissions'  => ['pos.access', 'pos.refund'],
            'landing_page' => 'pos',
            'username'     => 'cashier',
            'password'     => 'cashier123',
            'full_name'    => 'Demo Cashier',
        ],
        [
            'name'         => 'HR Manager',
            'description'  => 'Employee records, attendance, and HR requests.',
            'permissions'  => ['hr.employees.view', 'hr.employees.edit', 'hr.attendance.view', 'hr.attendance.edit', 'hr.requests.view', 'hr.requests.approve'],
            'landing_page' => 'hr',
            'username'     => 'hr.manager',
            'password'     => 'hr123',
            'full_name'    => 'Demo HR Manager',
        ],
        [
            'name'         => 'Finance Manager',
            'description'  => 'Finance requests: reimbursements and purchase approvals.',
            'permissions'  => ['finance.requests.view', 'finance.requests.approve', 'hr.employees.view'],
            'landing_page' => 'finance',
            'username'     => 'finance.manager',
            'password'     => 'finance123',
            'full_name'    => 'Demo Finance Manager',
        ],
    ];

    foreach ($roleDefs as $def) {
        $pdo->prepare(
            "INSERT OR IGNORE INTO roles (name, description, permissions, landing_page)
             VALUES (:name, :desc, :perms, :landing)"
        )->execute([
            ':name'    => $def['name'],
            ':desc'    => $def['description'],
            ':perms'   => json_encode($def['permissions']),
            ':landing' => $def['landing_page'],
        ]);
        $roleId = $pdo->query(
            'SELECT id FROM roles WHERE name = ' . $pdo->quote($def['name'])
        )->fetchColumn();

        $pdo->prepare(
            "INSERT OR IGNORE INTO admin_users (username, password_hash, full_name, role_id)
             VALUES (:username, :hash, :full_name, :role)"
        )->execute([
            ':username'  => $def['username'],
            ':hash'      => password_hash($def['password'], PASSWORD_DEFAULT),
            ':full_name' => $def['full_name'],
            ':role'      => $roleId,
        ]);
    }

    seedEmployeeSelfServiceDemo($pdo);
}

/**
 * Seeds one demo employee record plus an "Employee" role and a login account
 * linked to that record via admin_users.employee_id — the FK that lets
 * requireAnyPermission-gated endpoints resolve "which employee is THIS
 * session" for self-service (see auth.php's getSessionEmployeeId()).
 * Only ever creates rows that don't already exist by employee_no/name/username.
 */
function seedEmployeeSelfServiceDemo(PDO $pdo): void
{
    $pdo->prepare(
        "INSERT OR IGNORE INTO roles (name, description, permissions, landing_page)
         VALUES ('Employee', 'Self-service: own attendance, leave/OT requests, and reimbursement requests.', :perms, 'employee')"
    )->execute([':perms' => json_encode(['employee.self'])]);
    $employeeRoleId = $pdo->query(
        "SELECT id FROM roles WHERE name = 'Employee'"
    )->fetchColumn();

    $pdo->prepare(
        "INSERT OR IGNORE INTO employees (employee_no, full_name, position, department, hire_date, employment_status, leave_balance)
         VALUES ('EMP-0001', 'Juan Dela Cruz', 'Sales Associate', 'Operations', date('now'), 'Active', 15)"
    )->execute();
    $demoEmployeeId = $pdo->query(
        "SELECT id FROM employees WHERE employee_no = 'EMP-0001'"
    )->fetchColumn();

    $pdo->prepare(
        "INSERT OR IGNORE INTO admin_users (username, password_hash, full_name, role_id, employee_id)
         VALUES ('employee', :hash, 'Juan Dela Cruz', :role, :emp)"
    )->execute([
        ':hash' => password_hash('employee123', PASSWORD_DEFAULT),
        ':role' => $employeeRoleId,
        ':emp'  => $demoEmployeeId,
    ]);
}

/**
 * Seeds the products table with local Philippine grocery items so the system
 * works immediately without any manual data entry.
 */
function seedProducts(PDO $pdo): void
{
    $products = [
        // name, sku, price, category, stock_quantity, unit, emoji
        ['Banana (Lakatan)',    'FRU-001', 65.00,  'Fruits',     40, 'kg', '🍌'],
        ['Mango (Ripe)',        'FRU-002', 150.00, 'Fruits',     3,  'kg', '🥭'],
        ['Kalamansi',           'FRU-003', 80.00,  'Fruits',     0,  'kg', '🍋'],
        ['Red Apple',           'FRU-004', 110.00, 'Fruits',     25, 'kg', '🍎'],
        ['Watermelon',          'FRU-005', 45.00,  'Fruits',     12, 'kg', '🍉'],
        ['Pineapple',           'FRU-006', 70.00,  'Fruits',     15, 'pc', '🍍'],

        ['Tomato',               'VEG-001', 60.00,  'Vegetables', 30, 'kg', '🍅'],
        ['Carrot',                'VEG-002', 75.00,  'Vegetables', 20, 'kg', '🥕'],
        ['Cabbage',                'VEG-003', 55.00,  'Vegetables', 2,  'kg', '🥬'],
        ['Eggplant (Talong)',       'VEG-004', 65.00,  'Vegetables', 18, 'kg', '🍆'],
        ['Potato',                   'VEG-005', 70.00,  'Vegetables', 35, 'kg', '🥔'],
        ['Bell Pepper',                'VEG-006', 120.00, 'Vegetables', 0,  'kg', '🫑'],

        ['Fresh Milk 1L',       'DAI-001', 95.00,  'Dairy', 22, 'pc', '🥛'],
        ['Cheddar Cheese',      'DAI-002', 180.00, 'Dairy', 10, 'pc', '🧀'],
        ['Butter Block',        'DAI-003', 145.00, 'Dairy', 4,  'pc', '🧈'],
        ['Yogurt Cup',          'DAI-004', 55.00,  'Dairy', 16, 'pc', '🍦'],

        ['Pandesal (pack of 10)', 'BAK-001', 45.00, 'Bakery', 14, 'pc', '🍞'],
        ['Croissant',              'BAK-002', 60.00, 'Bakery', 8,  'pc', '🥐'],
        ['Ensaymada',                'BAK-003', 50.00, 'Bakery', 0,  'pc', '🥯'],
        ['Cupcake',                    'BAK-004', 45.00, 'Bakery', 20, 'pc', '🧁'],

        ['Bottled Water 500ml', 'BEV-001', 20.00,  'Beverages', 60, 'pc', '💧'],
        ['Buko Juice 1L',       'BEV-002', 90.00,  'Beverages', 9,  'pc', '🥥'],
        ['Ground Coffee',       'BEV-003', 220.00, 'Beverages', 11, 'pc', '☕'],
        ['Soda Can',            'BEV-004', 35.00,  'Beverages', 2,  'pc', '🥤'],

        ['Potato Chips',   'SNK-001', 55.00,  'Snacks', 26, 'pc', '🍟'],
        ['Chocolate Bar',  'SNK-002', 48.00,  'Snacks', 19, 'pc', '🍫'],
        ['Mixed Nuts',     'SNK-003', 130.00, 'Snacks', 6,  'pc', '🥜'],
        ['Dried Mangoes',  'SNK-004', 95.00,  'Snacks', 0,  'pc', '🍘'],
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO products (name, sku, price, category, stock_quantity, unit, emoji)
         VALUES (:name, :sku, :price, :category, :stock, :unit, :emoji)"
    );

    foreach ($products as [$name, $sku, $price, $category, $stock, $unit, $emoji]) {
        $stmt->execute([
            ':name'     => $name,
            ':sku'      => $sku,
            ':price'    => $price,
            ':category' => $category,
            ':stock'    => $stock,
            ':unit'     => $unit,
            ':emoji'    => $emoji,
        ]);
    }
}
