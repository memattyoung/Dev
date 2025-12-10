<?php
// Shop Inventory Viewer (Managers only)

// ===== CONFIG =====
$dbHost = getenv('BROWNS_DB_HOST');
$dbName = getenv('BROWNS_DB_NAME');
$dbUser = getenv('BROWNS_DB_USER');
$dbPass = getenv('BROWNS_DB_PASS');

if (!$dbHost || !$dbName || !$dbUser || !$dbPass) {
    die("Database environment variables are not set. Check Render env vars.");
}

date_default_timezone_set('America/New_York');

session_start();

// Must be logged in
if (empty($_SESSION['logged_in'])) {
    header("Location: index.php");
    exit;
}

// Must be manager
if (empty($_SESSION['empManager'])) {
    http_response_code(403);
    echo "Not authorized. Manager access only.";
    exit;
}

$empAAA  = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName = $_SESSION['empName'] ?? 'Manager';

$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

/* =========================================================
   ADD NEW SHOP INVENTORY – STEP-BY-STEP LOGIC
   ========================================================= */

$addMode            = 'make_model'; // 'make_model' or 'details'
$addError           = '';
$addHasDefaults     = false;

$addMake            = '';
$addModel           = '';
$addCategory        = '';
$addDescription     = '';
$addQuantity        = '';
$addLocation        = '';
$addInvoice         = '';

$categoryOptions = [
    'DRIVER EQUIPMENT',
    'REPLACEMENT PART',
    'SHOP EQUIPMENT',
    'CLEANING SUPPLY',
    'OFFICE EQUIPMENT',
    'OTHER'
];

// For location select: from Location.Location
try {
    $locStmt = $pdo->query("
        SELECT Location
        FROM Location
        WHERE Location IS NOT NULL AND Location <> ''
        ORDER BY Location
    ");
    $locationOptions = $locStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $locationOptions = [];
}

// Handle POST for Add workflow
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_action'])) {
    $action = $_POST['add_action'];

    if ($action === 'cancel_add') {
        // Do nothing – stay in default make/model mode with blank fields
        $addMode = 'make_model';
    } elseif ($action === 'start_add') {
        // STEP 1: Got Make/Model, now look for existing to default Category/Description
        $addMode = 'details';

        $addMake  = strtoupper(trim($_POST['add_make']  ?? ''));
        $addModel = strtoupper(trim($_POST['add_model'] ?? ''));

        if ($addMake === '' || $addModel === '') {
            $addError = "MAKE and MODEL are required.";
            $addMode  = 'make_model';
        } else {
            // Look for existing record (any location) with same MAKE + MODEL to default Category/Description
            $stmtExisting = $pdo->prepare("
                SELECT Category, Description
                FROM ShopInventory
                WHERE Make = :make
                  AND Model = :model
                ORDER BY LastUpdate DESC
                LIMIT 1
            ");
            $stmtExisting->execute([
                ':make'  => $addMake,
                ':model' => $addModel,
            ]);
            $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $addHasDefaults = true;
                $addCategory    = strtoupper(trim($existing['Category'] ?? ''));
                $addDescription = strtoupper(trim($existing['Description'] ?? ''));
            } else {
                $addHasDefaults = false;
                $addCategory    = '';
                $addDescription = '';
            }
        }
    } elseif ($action === 'save_add') {
        // STEP 2: Save final item (insert or update) + write AuditLog
        $addMode = 'details';

        // Rehydrate all fields from POST, force UPPERCASE on prompts
        $addMake        = strtoupper(trim($_POST['add_make']        ?? ''));
        $addModel       = strtoupper(trim($_POST['add_model']       ?? ''));
        $addCategory    = strtoupper(trim($_POST['add_category']    ?? ''));
        $addDescription = strtoupper(trim($_POST['add_description'] ?? ''));
        $addQuantity    = strtoupper(trim($_POST['add_quantity']    ?? ''));
        $addLocation    = strtoupper(trim($_POST['add_location']    ?? ''));
        $addInvoice     = strtoupper(trim($_POST['add_invoice']     ?? ''));

        // Basic validation
        if ($addMake === '' || $addModel === '') {
            $addError = "MAKE and MODEL are required.";
        } elseif ($addCategory === '') {
            $addError = "CATEGORY is required.";
        } elseif ($addDescription === '') {
            $addError = "DESCRIPTION is required.";
        } elseif ($addLocation === '') {
            $addError = "LOCATION is required.";
        } elseif ($addQuantity === '' || !is_numeric($addQuantity) || (float)$addQuantity <= 0) {
            $addError = "QUANTITY must be a positive number.";
        }

        if ($addError === '') {
            $qtyToAdd = (float)$addQuantity;

            try {
                $pdo->beginTransaction();

                // Does this MAKE+MODEL already exist in this LOCATION?
                $stmtFind = $pdo->prepare("
                    SELECT ID, Quantity
                    FROM ShopInventory
                    WHERE Make = :make
                      AND Model = :model
                      AND Location = :loc
                    LIMIT 1
                ");
                $stmtFind->execute([
                    ':make' => $addMake,
                    ':model'=> $addModel,
                    ':loc'  => $addLocation,
                ]);
                $existingRow = $stmtFind->fetch(PDO::FETCH_ASSOC);

                $now = date('Y-m-d H:i:s');
                $finalQty = $qtyToAdd;

                if ($existingRow) {
                    // Update existing: add quantity to previous record
                    $newQty = (float)($existingRow['Quantity'] ?? 0) + $qtyToAdd;
                    $finalQty = $newQty;

                    $stmtUpdate = $pdo->prepare("
                        UPDATE ShopInventory
                        SET Category = :cat,
                            Description = :descr,
                            Quantity = :qty,
                            LastUpdate = :lu
                        WHERE ID = :id
                    ");
                    $stmtUpdate->execute([
                        ':cat'   => $addCategory,
                        ':descr' => $addDescription,
                        ':qty'   => $newQty,
                        ':lu'    => $now,
                        ':id'    => $existingRow['ID'],
                    ]);
                } else {
                    // Insert new record
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO ShopInventory
                            (Make, Model, Category, Description, Quantity, Location, LastUpdate)
                        VALUES
                            (:make, :model, :cat, :descr, :qty, :loc, :lu)
                    ");
                    $stmtInsert->execute([
                        ':make'  => $addMake,
                        ':model' => $addModel,
                        ':cat'   => $addCategory,
                        ':descr' => $addDescription,
                        ':qty'   => $qtyToAdd,
                        ':loc'   => $addLocation,
                        ':lu'    => $now,
                    ]);
                }

                // Write AuditLog record
                $stmtAudit = $pdo->prepare("
                    INSERT INTO AuditLog
                        (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity, Make, Model, Description)
                    VALUES
                        (:empId, :empName, '', :toLoc, '', 'ADD TO STOCK', :invoice, '', '', '', 'MOBILE', 'MOBILE', :lastUpdate, 'Battery', :qty, :make, :model, :descr)
                ");
                $stmtAudit->execute([
                    ':empId'      => $empAAA,
                    ':empName'    => $empName,
                    ':toLoc'      => $addLocation,
                    ':invoice'    => $addInvoice,
                    ':lastUpdate' => $now,
                    ':qty'        => $finalQty,
                    ':make'       => $addMake,
                    ':model'      => $addModel,
                    ':descr'      => $addDescription,
                ]);

                $pdo->commit();

                // After success, redirect to clear POST and show updated table
                header("Location: shop_inventory.php?added=1");
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $addError = "Error adding to stock: " . htmlspecialchars($e->getMessage());
            }
        }
    }
}

/* =========================================================
   EXISTING FILTER/VIEW LOGIC
   ========================================================= */

// Filters
$filterMake     = trim($_GET['make'] ?? '');
$filterModel    = trim($_GET['model'] ?? '');
$filterLoc      = trim($_GET['loc'] ?? '');
$filterCategory = trim($_GET['cat'] ?? '');

$sql = "
    SELECT
        ID,
        Make,
        Model,
        Category,
        Description,
        Quantity,
        Location,
        LastUpdate
    FROM ShopInventory
    WHERE 1=1
";

$params = [];

if ($filterMake !== '') {
    $sql .= " AND Make = :make";
    $params[':make'] = $filterMake;
}
if ($filterModel !== '') {
    $sql .= " AND Model = :model";
    $params[':model'] = $filterModel;
}
if ($filterLoc !== '') {
    $sql .= " AND Location = :loc";
    $params[':loc'] = $filterLoc;
}
if ($filterCategory !== '') {
    $sql .= " AND Category = :cat";
    $params[':cat'] = $filterCategory;
}

$sql .= " ORDER BY Make, Model, Location";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pull distinct lists for filters
$distinctMakes  = $pdo->query("SELECT DISTINCT Make FROM ShopInventory ORDER BY Make")->fetchAll(PDO::FETCH_COLUMN);
$distinctModels = $pdo->query("SELECT DISTINCT Model FROM ShopInventory ORDER BY Model")->fetchAll(PDO::FETCH_COLUMN);
$distinctLocs   = $pdo->query("SELECT DISTINCT Location FROM ShopInventory ORDER BY Location")->fetchAll(PDO::FETCH_COLUMN);
$distinctCats   = $pdo->query("SELECT DISTINCT Category FROM ShopInventory ORDER BY Category")->fetchAll(PDO::FETCH_COLUMN);

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Browns Towing – Shop Inventory</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 10px;
            background: #f9fafb;
        }
        h1, h2 {
            text-align: center;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .card {
            background: #ffffff;
            border-radius: 8px;
            padding: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-top: 12px;
        }
        .btn {
            display: inline-block;
            text-align: center;
            padding: 10px;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            border: none;
            cursor: pointer;
        }
        .btn-secondary {
            background: #4b5563;
        }
        .btn:active {
            transform: scale(0.98);
        }
        .table-container {
            max-height: 65vh;
            overflow-y: auto;
            margin-top: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 6px;
        }
        th {
            background: #f3f4f6;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        select, input[type="text"], input[type="number"], textarea {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        /* Make prompts visually UPPERCASE */
        .uppercase-input {
            text-transform: uppercase;
        }
        .filter-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        @media (min-width: 800px) {
            .filter-row {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        .label-block {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .flex-row-between {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:8px;
            flex-wrap:wrap;
        }
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
        .msg {
            padding: 8px;
            border-radius: 4px;
            margin-top: 8px;
            font-size: 13px;
        }
        .msg-success {
            background: #dcfce7;
            color: #166534;
        }
        .msg-error {
            background: #fee2e2;
            color:#991b1b;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Shop Inventory</h1>

    <div class="card">
        <div class="flex-row-between">
            <p class="small-note" style="margin:0;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong> (<?= htmlspecialchars($empAAA) ?>) – Manager.
            </p>
            <a href="index.php?view=manager_home" class="btn btn-secondary">
                Back to Manager Menu
            </a>
        </div>
    </div>

    <?php if (isset($_GET['added']) && $_GET['added'] == '1'): ?>
        <div class="card msg msg-success">
            Shop inventory was successfully updated.
        </div>
    <?php endif; ?>

    <!-- ADD SHOP INVENTORY CARD -->
    <div class="card">
        <h2 style="margin-top:0; font-size:18px;">Add to Shop Inventory</h2>

        <?php if ($addError !== ''): ?>
            <div class="msg msg-error">
                <?= htmlspecialchars($addError) ?>
            </div>
        <?php endif; ?>

        <?php if ($addMode === 'make_model'): ?>
            <!-- STEP 1: Prompt for MAKE + MODEL -->
            <form method="post">
                <input type="hidden" name="add_action" value="start_add">
                <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:8px;">
                    <div>
                        <label class="label-block">MAKE</label>
                        <input type="text"
                               name="add_make"
                               class="uppercase-input"
                               value="<?= htmlspecialchars($addMake) ?>">
                    </div>
                    <div>
                        <label class="label-block">MODEL</label>
                        <input type="text"
                               name="add_model"
                               class="uppercase-input"
                               value="<?= htmlspecialchars($addModel) ?>">
                    </div>
                </div>
                <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit" class="btn">Next</button>
                    <button type="submit" name="add_action" value="cancel_add" class="btn btn-secondary">
                        Cancel
                    </button>
                </div>
                <p class="small-note" style="margin-top:8px;">
                    First, enter MAKE and MODEL. If this combination already exists, Category and Description will be defaulted on the next step.
                </p>
            </form>
        <?php else: ?>
            <!-- STEP 2: Details (CATEGORY, DESCRIPTION, QUANTITY, LOCATION, INVOICE) -->
            <form method="post">
                <input type="hidden" name="add_action" value="save_add">
                <!-- keep MAKE/MODEL across post -->
                <input type="hidden" name="add_make"  value="<?= htmlspecialchars($addMake) ?>">
                <input type="hidden" name="add_model" value="<?= htmlspecialchars($addModel) ?>">

                <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:8px;">
                    <div>
                        <label class="label-block">MAKE</label>
                        <input type="text"
                               class="uppercase-input"
                               value="<?= htmlspecialchars($addMake) ?>"
                               readonly>
                    </div>
                    <div>
                        <label class="label-block">MODEL</label>
                        <input type="text"
                               class="uppercase-input"
                               value="<?= htmlspecialchars($addModel) ?>"
                               readonly>
                    </div>
                </div>

                <div style="margin-top:8px; display:grid; grid-template-columns: repeat(2, 1fr); gap:8px;">
                    <div>
                        <label class="label-block">CATEGORY</label>
                        <select name="add_category" class="uppercase-input">
                            <option value="">-- SELECT CATEGORY --</option>
                            <?php foreach ($categoryOptions as $catOpt): ?>
                                <option value="<?= htmlspecialchars($catOpt) ?>"
                                    <?= ($catOpt === $addCategory ? 'selected' : '') ?>>
                                    <?= htmlspecialchars($catOpt) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label-block">LOCATION</label>
                        <select name="add_location" class="uppercase-input">
                            <option value="">-- SELECT LOCATION --</option>
                            <?php foreach ($locationOptions as $loc): ?>
                                <?php $locUpper = strtoupper($loc); ?>
                                <option value="<?= htmlspecialchars($locUpper) ?>"
                                    <?= ($locUpper === $addLocation ? 'selected' : '') ?>>
                                    <?= htmlspecialchars($locUpper) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-top:8px;">
                    <label class="label-block">DESCRIPTION</label>
                    <textarea name="add_description"
                              class="uppercase-input"
                              rows="2"><?= htmlspecialchars($addDescription) ?></textarea>
                </div>

                <div style="margin-top:8px; display:grid; grid-template-columns: repeat(2, 1fr); gap:8px;">
                    <div>
                        <label class="label-block">QUANTITY</label>
                        <input type="number"
                               step="1"
                               min="1"
                               name="add_quantity"
                               class="uppercase-input"
                               value="<?= htmlspecialchars($addQuantity) ?>">
                    </div>
                    <div>
                        <label class="label-block">INVOICE (optional)</label>
                        <input type="text"
                               name="add_invoice"
                               class="uppercase-input"
                               value="<?= htmlspecialchars($addInvoice) ?>">
                    </div>
                </div>

                <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit" class="btn">Save</button>
                    <button type="submit" name="add_action" value="cancel_add" class="btn btn-secondary">
                        Cancel
                    </button>
                </div>

                <p class="small-note" style="margin-top:8px;">
                    All entries are stored in UPPERCASE. If MAKE/MODEL already exist in the selected LOCATION,
                    the quantity will be added to the existing record; otherwise a new record will be created.
                    An audit log record (ADD TO STOCK) will also be written.
                </p>
            </form>
        <?php endif; ?>
    </div>

    <!-- EXISTING FILTER CARD -->
    <div class="card">
        <form method="get">
            <div class="filter-row">
                <div>
                    <label class="label-block">Make</label>
                    <select name="make">
                        <option value="">All</option>
                        <?php foreach ($distinctMakes as $mk): ?>
                            <option value="<?= htmlspecialchars($mk) ?>"
                                <?= ($mk === $filterMake ? 'selected' : '') ?>>
                                <?= htmlspecialchars($mk) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="label-block">Model</label>
                    <select name="model">
                        <option value="">All</option>
                        <?php foreach ($distinctModels as $m): ?>
                            <option value="<?= htmlspecialchars($m) ?>"
                                <?= ($m === $filterModel ? 'selected' : '') ?>>
                                <?= htmlspecialchars($m) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="label-block">Location</label>
                    <select name="loc">
                        <option value="">All</option>
                        <?php foreach ($distinctLocs as $loc): ?>
                            <option value="<?= htmlspecialchars($loc) ?>"
                                <?= ($loc === $filterLoc ? 'selected' : '') ?>>
                                <?= htmlspecialchars($loc) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="label-block">Category</label>
                    <select name="cat">
                        <option value="">All</option>
                        <?php foreach ($distinctCats as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"
                                <?= ($cat === $filterCategory ? 'selected' : '') ?>>
                                <?= htmlspecialchars($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                <button type="submit" class="btn">Apply Filters</button>
                <a href="shop_inventory.php" class="btn btn-secondary">Clear</a>
            </div>
        </form>
        <p class="small-note" style="margin-top:8px;">
            This view shows current shop equipment, parts, and supplies.
        </p>
    </div>

    <div class="card">
        <div class="table-container">
            <table>
                <tr>
                    <th>ID</th>
                    <th>Make</th>
                    <th>Model</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Qty</th>
                    <th>Location</th>
                    <th>Last Updated</th>
                </tr>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;">No records found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['ID']) ?></td>
                            <td><?= htmlspecialchars($r['Make']) ?></td>
                            <td><?= htmlspecialchars($r['Model']) ?></td>
                            <td><?= htmlspecialchars($r['Category']) ?></td>
                            <td><?= htmlspecialchars($r['Description']) ?></td>
                            <td><?= htmlspecialchars($r['Quantity']) ?></td>
                            <td><?= htmlspecialchars($r['Location']) ?></td>
                            <td><?= htmlspecialchars($r['LastUpdate']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
</body>
</html>
