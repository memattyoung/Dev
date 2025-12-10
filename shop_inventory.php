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
   DESTINATION LISTS (Location + Trucks)
   ========================================================= */
$destinationOptions = [];

try {
    // Shop locations
    $stmtLoc = $pdo->query("
        SELECT Location
        FROM Location
        WHERE Location IS NOT NULL AND Location <> ''
        ORDER BY Location
    ");
    $shopLocs = $stmtLoc->fetchAll(PDO::FETCH_COLUMN);

    foreach ($shopLocs as $loc) {
        $locTrim = trim($loc);
        if ($locTrim !== '') {
            $destinationOptions[] = [
                'value' => $locTrim,
                'label' => $locTrim . ' (SHOP)'
            ];
        }
    }

    // Trucks
    $stmtTrk = $pdo->query("
        SELECT Truck
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
        ORDER BY Truck
    ");
    $trucks = $stmtTrk->fetchAll(PDO::FETCH_COLUMN);

    foreach ($trucks as $trk) {
        $trkTrim = trim($trk);
        if ($trkTrim !== '') {
            $destinationOptions[] = [
                'value' => $trkTrim,
                'label' => $trkTrim . ' (TRUCK)'
            ];
        }
    }
} catch (Exception $e) {
    // silently ignore, just no destinations
}

/* =========================================================
   ACTION STATE (TRANSFER / CORRECT COUNT)
   ========================================================= */
$selectedRow      = null;
$currentAction    = ''; // 'transfer' or 'correct'
$actionError      = '';
$actionSuccess    = '';

$transferQty      = '';
$transferTo       = '';

$correctNewQty    = '';
$correctReason    = '';

/**
 * Load a single ShopInventory row by ID
 */
function loadShopInventoryRowById(PDO $pdo, $id) {
    $stmt = $pdo->prepare("
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
        WHERE ID = :id
    ");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/* =========================================================
   HANDLE POST (SAVE TRANSFER / SAVE CORRECT COUNT)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_action'])) {
    $doAction = $_POST['do_action'];
    $id       = (int)($_POST['record_id'] ?? 0);

    if ($id <= 0) {
        $actionError = "Invalid record selected.";
    } else {
        $row = loadShopInventoryRowById($pdo, $id);
        if (!$row) {
            $actionError = "Selected record not found.";
        } else {
            $selectedRow = $row; // keep context

            if ($doAction === 'transfer_save') {
                $currentAction = 'transfer';

                $transferQty = trim($_POST['transfer_qty'] ?? '');
                $transferTo  = trim($_POST['transfer_to'] ?? '');

                if ($transferQty === '' || !is_numeric($transferQty) || (float)$transferQty <= 0) {
                    $actionError = "Transfer quantity must be a positive number.";
                } elseif ($transferTo === '') {
                    $actionError = "Destination location is required.";
                } else {
                    $qtyMove    = (float)$transferQty;
                    $currentQty = (float)($row['Quantity'] ?? 0);

                    if ($qtyMove > $currentQty) {
                        $actionError = "Cannot transfer more than current quantity. Current = {$currentQty}.";
                    } elseif (strcasecmp($transferTo, $row['Location']) === 0) {
                        $actionError = "Destination cannot be the same as the current location.";
                    } else {
                        try {
                            $pdo->beginTransaction();

                            $now       = date('Y-m-d H:i:s');
                            $fromLoc   = $row['Location'];
                            $make      = $row['Make'];
                            $model     = $row['Model'];
                            $descr     = $row['Description'];
                            $category  = $row['Category'];

                            // 1) Update source row quantity
                            $newSourceQty = $currentQty - $qtyMove;
                            $stmtUpdSrc = $pdo->prepare("
                                UPDATE ShopInventory
                                SET Quantity = :qty, LastUpdate = :lu
                                WHERE ID = :id
                            ");
                            $stmtUpdSrc->execute([
                                ':qty' => $newSourceQty,
                                ':lu'  => $now,
                                ':id'  => $row['ID'],
                            ]);

                            // 2) Update or insert destination row
                            $stmtFindDest = $pdo->prepare("
                                SELECT ID, Quantity
                                FROM ShopInventory
                                WHERE Make = :make
                                  AND Model = :model
                                  AND Location = :loc
                                LIMIT 1
                            ");
                            $stmtFindDest->execute([
                                ':make' => $make,
                                ':model'=> $model,
                                ':loc'  => $transferTo,
                            ]);
                            $destRow = $stmtFindDest->fetch(PDO::FETCH_ASSOC);

                            if ($destRow) {
                                $destQtyNew = (float)$destRow['Quantity'] + $qtyMove;
                                $stmtUpdDst = $pdo->prepare("
                                    UPDATE ShopInventory
                                    SET Quantity = :qty, LastUpdate = :lu
                                    WHERE ID = :id
                                ");
                                $stmtUpdDst->execute([
                                    ':qty' => $destQtyNew,
                                    ':lu'  => $now,
                                    ':id'  => $destRow['ID'],
                                ]);
                            } else {
                                $stmtInsDst = $pdo->prepare("
                                    INSERT INTO ShopInventory
                                        (Make, Model, Category, Description, Quantity, Location, LastUpdate)
                                    VALUES
                                        (:make, :model, :cat, :descr, :qty, :loc, :lu)
                                ");
                                $stmtInsDst->execute([
                                    ':make'  => $make,
                                    ':model' => $model,
                                    ':cat'   => $category,
                                    ':descr' => $descr,
                                    ':qty'   => $qtyMove,
                                    ':loc'   => $transferTo,
                                    ':lu'    => $now,
                                ]);
                            }

                            // 3) AuditLog insert
                            $stmtAudit = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity, Make, Model, Description)
                                VALUES
                                    (:empId, :empName, :fromLoc, :toLoc, '', 'Stock Transfer', '', '', '', '', 'MOBILE', 'MOBILE', :lastUpdate, 'STOCK', :qty, :make, :model, :descr)
                            ");
                            $stmtAudit->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':toLoc'      => $transferTo,
                                ':lastUpdate' => $now,
                                ':qty'        => $qtyMove,
                                ':make'       => $make,
                                ':model'      => $model,
                                ':descr'      => $descr,
                            ]);

                            $pdo->commit();

                            $actionSuccess = "Stock was successfully transferred.";
                            // After submit, hide area
                            $currentAction = '';
                            $selectedRow   = null;
                            $transferQty   = '';
                            $transferTo    = '';

                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $actionError = "Error performing stock transfer: " . htmlspecialchars($e->getMessage());
                        }
                    }
                }

            } elseif ($doAction === 'correct_save') {
                $currentAction = 'correct';

                $correctNewQty = trim($_POST['correct_qty'] ?? '');
                $correctReason = trim($_POST['correct_reason'] ?? '');

                if ($correctNewQty === '' || !is_numeric($correctNewQty) || (float)$correctNewQty < 0) {
                    $actionError = "New count must be a number greater than or equal to zero.";
                } elseif ($correctReason === '') {
                    // Reason required
                    $actionError = "Reason is required.";
                } else {
                    $newQty     = (float)$correctNewQty;
                    $currentQty = (float)$row['Quantity'];

                    if ($newQty == $currentQty) {
                        $actionError = "New count matches the current quantity. No changes to save.";
                    } else {
                        // Reason limited to 255 chars
                        $reasonClean = mb_substr($correctReason, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $now      = date('Y-m-d H:i:s');
                            $loc      = $row['Location'];
                            $make     = $row['Make'];
                            $model    = $row['Model'];
                            $descr    = $row['Description'];

                            // 1) Update ShopInventory
                            $stmtUpd = $pdo->prepare("
                                UPDATE ShopInventory
                                SET Quantity = :qty, LastUpdate = :lu
                                WHERE ID = :id
                            ");
                            $stmtUpd->execute([
                                ':qty' => $newQty,
                                ':lu'  => $now,
                                ':id'  => $row['ID'],
                            ]);

                            // 2) AuditLog insert
                            $stmtAudit = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity, Make, Model, Description)
                                VALUES
                                    (:empId, :empName, :fromLoc, :toLoc, '', 'Stock Transfer', '', '', '', :reason, 'MOBILE', 'MOBILE', :lastUpdate, 'STOCK', :qty, :make, :model, :descr)
                            ");
                            $stmtAudit->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $loc,
                                ':toLoc'      => $loc,
                                ':reason'     => $reasonClean,
                                ':lastUpdate' => $now,
                                ':qty'        => $newQty,
                                ':make'       => $make,
                                ':model'      => $model,
                                ':descr'      => $descr,
                            ]);

                            $pdo->commit();

                            $actionSuccess = "Count was successfully corrected.";
                            // After submit, hide area
                            $currentAction = '';
                            $selectedRow   = null;
                            $correctNewQty = '';
                            $correctReason = '';

                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $actionError = "Error correcting count: " . htmlspecialchars($e->getMessage());
                        }
                    }
                }
            }
        }
    }
}

/* =========================================================
   HANDLE GET TO OPEN ACTION FOR A ROW
   ========================================================= */
if ($selectedRow === null && isset($_GET['id'], $_GET['act'])) {
    $id  = (int)$_GET['id'];
    $act = $_GET['act'];

    if ($id > 0 && in_array($act, ['transfer', 'correct'], true)) {
        $row = loadShopInventoryRowById($pdo, $id);
        if ($row) {
            $selectedRow   = $row;
            $currentAction = $act;
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
        .btn-small {
            padding: 4px 8px;
            font-size: 12px;
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

    <?php if ($actionError !== ''): ?>
        <div class="card msg msg-error">
            <?= htmlspecialchars($actionError) ?>
        </div>
    <?php endif; ?>

    <?php if ($actionSuccess !== ''): ?>
        <div class="card msg msg-success">
            <?= htmlspecialchars($actionSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedRow && $currentAction === 'transfer'): ?>
        <div class="card">
            <h2 style="margin-top:0; font-size:18px;">Transfer Stock</h2>
            <p class="small-note">
                Make: <strong><?= htmlspecialchars($selectedRow['Make']) ?></strong>,
                Model: <strong><?= htmlspecialchars($selectedRow['Model']) ?></strong>,
                Location: <strong><?= htmlspecialchars($selectedRow['Location']) ?></strong>,
                Current Qty: <strong><?= htmlspecialchars($selectedRow['Quantity']) ?></strong>
            </p>
            <form method="post">
                <input type="hidden" name="do_action" value="transfer_save">
                <input type="hidden" name="record_id" value="<?= (int)$selectedRow['ID'] ?>">

                <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:8px;">
                    <div>
                        <label class="label-block">Quantity to Transfer</label>
                        <input type="number"
                               name="transfer_qty"
                               min="1"
                               step="1"
                               value="<?= htmlspecialchars($transferQty) ?>">
                    </div>
                    <div>
                        <label class="label-block">Transfer To Location</label>
                        <select name="transfer_to">
                            <option value="">Select Destination</option>
                            <?php foreach ($destinationOptions as $opt): ?>
                                <option value="<?= htmlspecialchars($opt['value']) ?>"
                                    <?= ($opt['value'] === $transferTo ? 'selected' : '') ?>>
                                    <?= htmlspecialchars($opt['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit" class="btn">Submit</button>
                    <a href="shop_inventory.php" class="btn btn-secondary">Cancel</a>
                </div>

                <p class="small-note" style="margin-top:8px;">
                    Transfer will subtract from the current location and add to the destination.
                    Quantity cannot go negative.
                </p>
            </form>
        </div>
    <?php elseif ($selectedRow && $currentAction === 'correct'): ?>
        <div class="card">
            <h2 style="margin-top:0; font-size:18px;">Correct Count</h2>
            <p class="small-note">
                Make: <strong><?= htmlspecialchars($selectedRow['Make']) ?></strong>,
                Model: <strong><?= htmlspecialchars($selectedRow['Model']) ?></strong>,
                Location: <strong><?= htmlspecialchars($selectedRow['Location']) ?></strong>,
                Current Qty: <strong><?= htmlspecialchars($selectedRow['Quantity']) ?></strong>
            </p>
            <form method="post">
                <input type="hidden" name="do_action" value="correct_save">
                <input type="hidden" name="record_id" value="<?= (int)$selectedRow['ID'] ?>">

                <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap:8px;">
                    <div>
                        <label class="label-block">New Count</label>
                        <input type="number"
                               name="correct_qty"
                               min="0"
                               step="1"
                               value="<?= htmlspecialchars($correctNewQty) ?>">
                    </div>
                    <div>
                        <label class="label-block">Reason (max 255 chars)</label>
                        <textarea name="correct_reason"
                                  rows="2"
                                  maxlength="255"><?= htmlspecialchars($correctReason) ?></textarea>
                    </div>
                </div>

                <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit" class="btn">Submit</button>
                    <a href="shop_inventory.php" class="btn btn-secondary">Cancel</a>
                </div>

                <p class="small-note" style="margin-top:8px;">
                    Reason is required and stored in the audit log. Quantity cannot be negative.
                </p>
            </form>
        </div>
    <?php endif; ?>

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
            Select a row below to transfer stock or correct the count.
        </p>
    </div>

    <div class="card">
        <div class="table-container">
            <table>
                <tr>
                    <!-- ID column removed -->
                    <th>Make</th>
                    <th>Model</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Qty</th>
                    <th>Location</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                </tr>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;">No records found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <!-- ID no longer shown -->
                            <td><?= htmlspecialchars($r['Make']) ?></td>
                            <td><?= htmlspecialchars($r['Model']) ?></td>
                            <td><?= htmlspecialchars($r['Category']) ?></td>
                            <td><?= htmlspecialchars($r['Description']) ?></td>
                            <td><?= htmlspecialchars($r['Quantity']) ?></td>
                            <td><?= htmlspecialchars($r['Location']) ?></td>
                            <td><?= htmlspecialchars($r['LastUpdate']) ?></td>
                            <td>
                                <!-- Transfer button -->
                                <form method="get" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= (int)$r['ID'] ?>">
                                    <input type="hidden" name="act" value="transfer">
                                    <!-- Preserve filters -->
                                    <input type="hidden" name="make" value="<?= htmlspecialchars($filterMake) ?>">
                                    <input type="hidden" name="model" value="<?= htmlspecialchars($filterModel) ?>">
                                    <input type="hidden" name="loc" value="<?= htmlspecialchars($filterLoc) ?>">
                                    <input type="hidden" name="cat" value="<?= htmlspecialchars($filterCategory) ?>">
                                    <button type="submit" class="btn btn-small">Transfer</button>
                                </form>
                                <!-- Correct Count button -->
                                <form method="get" style="display:inline; margin-left:4px;">
                                    <input type="hidden" name="id" value="<?= (int)$r['ID'] ?>">
                                    <input type="hidden" name="act" value="correct">
                                    <input type="hidden" name="make" value="<?= htmlspecialchars($filterMake) ?>">
                                    <input type="hidden" name="model" value="<?= htmlspecialchars($filterModel) ?>">
                                    <input type="hidden" name="loc" value="<?= htmlspecialchars($filterLoc) ?>">
                                    <input type="hidden" name="cat" value="<?= htmlspecialchars($filterCategory) ?>">
                                    <button type="submit" class="btn btn-small btn-secondary">Correct</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
</body>
</html>
