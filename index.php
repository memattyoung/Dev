<?php
// =======================================================
//  SECTION 1: CONFIGURATION
// =======================================================

// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Simple "password" for managers to access page
$appPassword = "GoldFish";

// Start session
session_start();


// =======================================================
//  SECTION 2: LOGIN GATE (NO OUTPUT BEFORE THIS POINT)
// =======================================================
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!empty($_POST['password']) && $_POST['password'] === $appPassword) {
            $_SESSION['logged_in'] = true;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = "Invalid password";
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2>Enter Password Tuna Marie</h2>
        <?php if (!empty($error)): ?>
            <p style='color:red;'><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>Password:</label><br>
            <input type="password" name="password"
                   style="width:100%; padding:8px; margin:8px 0;">
            <button type="submit" style="width:100%; padding:10px;">Enter</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}


// =======================================================
//  SECTION 3: DATABASE CONNECTION
// =======================================================
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}


// =======================================================
//  SECTION 4: ROUTING / STATE
// =======================================================
// view: menu | inventory | sell | transfer
$view = $_GET['view'] ?? 'menu';
$msg  = $_GET['msg']  ?? '';

// Pre-declare section vars
$invRows          = [];
$allLocations     = [];
$allBatteries     = [];
$sellError        = "";
$sellInfo         = null;

$transferError    = "";
$transferPreview  = null;
$transferMode     = 'truck';   // 'truck' or 'shop'
$transferToLoc    = '';
$truckList        = [];
$shopLocations    = [];


// =======================================================
//  SECTION 5: INVENTORY LOGIC (IF VIEW = inventory)
//  (Note: SOLD rows are always excluded)
// =======================================================
if ($view === 'inventory') {
    // Filters from GET
    $selectedLocation = isset($_GET['loc']) ? trim($_GET['loc']) : '';
    $selectedBattery  = isset($_GET['bat']) ? trim($_GET['bat']) : '';

    // 5.1 Get all unique Batteries & Locations for dropdowns
    //     Excludes SOLD from Inventory.
    $sqlAll = "
        SELECT 
            Battery.Battery AS Battery,
            Inventory.Location AS Location
        FROM Battery
        JOIN Inventory ON Battery.BatteryID = Inventory.BatteryID
        WHERE Inventory.Location <> 'SOLD' AND Inventory.Location <> 'SCRAPPED'
        GROUP BY Battery.Battery, Inventory.Location
        ORDER BY Battery.Battery, Inventory.Location
    ";
    $stmtAll = $pdo->query($sqlAll);
    $allRows = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allRows as $row) {
        if (!in_array($row['Location'], $allLocations, true)) {
            $allLocations[] = $row['Location'];
        }
        if (!in_array($row['Battery'], $allBatteries, true)) {
            $allBatteries[] = $row['Battery'];
        }
    }

    // 5.2 Main aggregated query with filters
    //     Base WHERE excludes SOLD globally.
    $sql = "
        SELECT 
            Battery.Battery AS Battery,
            COUNT(Battery.Battery) AS Quantity,
            Inventory.Location AS Location
        FROM Battery
        JOIN Inventory ON Battery.BatteryID = Inventory.BatteryID
        WHERE Inventory.Location <> 'SOLD' AND Inventory.Location <> 'SCRAPPED'
    ";
    $params = [];

    if ($selectedLocation !== '') {
        $sql .= " AND Inventory.Location = :loc";
        $params[':loc'] = $selectedLocation;
    }
    if ($selectedBattery !== '') {
        $sql .= " AND Battery.Battery = :bat";
        $params[':bat'] = $selectedBattery;
    }

    $sql .= "
        GROUP BY Battery.Battery, Inventory.Location
        ORDER BY Battery.Battery, Inventory.Location
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// =======================================================
//  SECTION 6: SELL BATTERY LOGIC (IF VIEW = sell)
//  (Already excludes SOLD in all lookups/updates)
// =======================================================
if ($view === 'sell') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // 6.1 Step 1: Lookup by BatteryID
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $sellError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Battery.BatteryID,
                        Battery.Battery,
                        Battery.DateCode,
                        Inventory.Location
                    FROM Battery
                    JOIN Inventory 
                      ON Battery.BatteryID = Inventory.BatteryID
                    WHERE Battery.BatteryID = :bid
                      AND Inventory.Location <> 'SOLD' AND Inventory.Location <> 'SCRAPPED'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // 6.2 Step 2: Confirm sale
        elseif (isset($_POST['confirm_sell'])) {
            $bid = trim($_POST['battery_id'] ?? '');

            if ($bid === '') {
                $sellError = "Missing BatteryID.";
            } else {
                // Re-fetch latest info & ensure not already SOLD
                $sql = "
                    SELECT 
                        Battery.BatteryID,
                        Battery.Battery,
                        Battery.DateCode,
                        Inventory.Location
                    FROM Battery
                    JOIN Inventory 
                      ON Battery.BatteryID = Inventory.BatteryID
                    WHERE Battery.BatteryID = :bid
                      AND Inventory.Location <> 'SOLD' AND Inventory.Location <> 'SCRAPPED'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $fromLoc = $row['Location'];

                        // Update Inventory: mark SOLD
                        $update = $pdo->prepare("
                            UPDATE Inventory 
                            SET Location = 'SOLD' 
                            WHERE BatteryID = :bid
                              AND Location <> 'SOLD' 
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog record
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer)
                            VALUES
                                ('WEBUSER', 'Tuna Marie', :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', :fromLoc, 'MOBILE')
                        ");
                        $insert->execute([
                            ':fromLoc'   => $fromLoc,
                            ':batteryId' => $row['BatteryID'],
                            ':battery'   => $row['Battery'],
                            ':dateCode'  => $row['DateCode'],
                        ]);

                        $pdo->commit();

                        header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=sold");
                        exit;

                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $sellError = "Error selling battery: " . $e->getMessage();
                    }
                }
            }
        }
    }
}


// =======================================================
//  SECTION 7: TRANSFER BATTERY LOGIC (IF VIEW = transfer)
// =======================================================
if ($view === 'transfer') {

    // 7.1 Load dropdown data: trucks and shop locations
    // Trucks
    $stmtTrucks = $pdo->query("
        SELECT DISTINCT Truck AS Truck
        FROM Trucks
        ORDER BY Truck
    ");
    $truckList = $stmtTrucks->fetchAll(PDO::FETCH_COLUMN);

    // Shop locations
    $stmtLocs = $pdo->query("
        SELECT DISTINCT Location AS Location
        FROM Location
        ORDER BY Location
    ");
    $shopLocations = $stmtLocs->fetchAll(PDO::FETCH_COLUMN);

    // Defaults for mode and to-location
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $transferMode  = $_POST['mode']   ?? 'truck';
        $transferToLoc = trim($_POST['to_loc'] ?? '');
    } else {
        $transferMode  = 'truck';
        $transferToLoc = '';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // 7.2 Step 1: Preview transfer (lookup + build summary)
        if (isset($_POST['preview_transfer'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $transferError = "Please enter a BatteryID.";
            } elseif ($transferToLoc === '') {
                $transferError = "Please select a destination.";
            } else {
                // Lookup battery, excluding SOLD and SCRAPPED
                $sql = "
                    SELECT 
                        Battery.BatteryID,
                        Battery.Battery,
                        Battery.DateCode,
                        Inventory.Location
                    FROM Battery
                    JOIN Inventory 
                      ON Battery.BatteryID = Inventory.BatteryID
                    WHERE Battery.BatteryID = :bid
                      AND Inventory.Location <> 'SOLD'
                      AND Inventory.Location <> 'SCRAPPED'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED.";
                } else {
                    $fromLoc = $row['Location'];

                    if ($fromLoc === $transferToLoc) {
                        $transferError = "From Location and To Location cannot be the same.";
                    } else {
                        // Build preview object
                        $transferPreview = [
                            'BatteryID' => $row['BatteryID'],
                            'Battery'   => $row['Battery'],
                            'DateCode'  => $row['DateCode'],
                            'FromLoc'   => $fromLoc,
                            'ToLoc'     => $transferToLoc,
                            'Mode'      => $transferMode,
                        ];
                    }
                }
            }
        }

        // 7.3 Step 2: Confirm transfer
        elseif (isset($_POST['confirm_transfer'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $fromLoc   = trim($_POST['from_loc'] ?? '');
            $toLoc     = trim($_POST['to_loc'] ?? '');
            $battery   = trim($_POST['battery'] ?? '');
            $dateCode  = trim($_POST['date_code'] ?? '');

            if ($bid === '' || $toLoc === '' || $fromLoc === '') {
                $transferError = "Missing transfer details.";
            } elseif ($fromLoc === $toLoc) {
                $transferError = "From Location and To Location cannot be the same.";
            } else {
                // Re-validate battery still valid (not SOLD/SCRAPPED)
                $sql = "
                    SELECT 
                        Battery.BatteryID,
                        Battery.Battery,
                        Battery.DateCode,
                        Inventory.Location
                    FROM Battery
                    JOIN Inventory 
                      ON Battery.BatteryID = Inventory.BatteryID
                    WHERE Battery.BatteryID = :bid
                      AND Inventory.Location <> 'SOLD'
                      AND Inventory.Location <> 'SCRAPPED'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it became SOLD/SCRAPPED.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        // 7.3.1 Update Inventory: move to new location
                        $update = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :toLoc
                            WHERE BatteryID = :bid
                              AND Location <> 'SOLD'
                              AND Location <> 'SCRAPPED'
                        ");
                        $update->execute([
                            ':toLoc' => $toLoc,
                            ':bid'   => $bid,
                        ]);

                        // 7.3.2 Insert AuditLog record
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer)
                            VALUES
                                ('WEBUSER', 'Tuna Marie', :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', :fromLoc, 'MOBILE')
                        ");
                        $insert->execute([
                            ':fromLoc'   => $fromLoc,
                            ':toLoc'     => $toLoc,
                            ':batteryId' => $bid,
                            ':battery'   => $battery,
                            ':dateCode'  => $dateCode,
                        ]);

                        $pdo->commit();

                        header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=transferred");
                        exit;

                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $transferError = "Error transferring battery: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Browns Towing Battery Program</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        /* ===================================================
           SECTION 8: STYLES
           =================================================== */
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
            max-width: 900px;
            margin: 0 auto;
        }
        .menu-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-top: 20px;
        }
        @media (min-width: 600px) {
            .menu-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        .btn {
            display: inline-block;
            text-align: center;
            padding: 12px;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 16px;
            border: none;
            width: 100%;
            box-sizing: border-box;
        }
        .btn-secondary {
            background: #4b5563;
        }
        .btn:active {
            transform: scale(0.98);
        }
        .card {
            background: #ffffff;
            border-radius: 8px;
            padding: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-top: 12px;
        }
        .table-container {
            max-height: 65vh;
            overflow-y: auto;
            margin-top: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
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
        .filter-row {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        @media (min-width: 600px) {
            .filter-row {
                flex-direction: row;
                align-items: center;
            }
        }
        select, input[type="text"] {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        .filters-actions {
            display: flex;
            gap: 6px;
            margin-top: 6px;
        }
        .text-center {
            text-align: center;
        }
        .msg {
            padding: 8px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 14px;
        }
        .msg-success {
            background: #dcfce7;
            color: #166534;
        }
        .msg-error {
            background: #fee2e2;
            color: #991b1b;
        }
        .label-block {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .mt-10 { margin-top: 10px; }
        .mt-6 { margin-top: 6px; }
        .radio-row {
            display: flex;
            gap: 12px;
            margin-top: 6px;
            align-items: center;
        }
    </style>
</head>
<body>
<div class="container">

    <!-- ===================================================
         SECTION 9: HEADER + GLOBAL MESSAGES
         =================================================== -->
    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php endif; ?>

    <?php if ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php endif; ?>


    <!-- ===================================================
         SECTION 10: MAIN MENU VIEW
         =================================================== -->
    <?php if ($view === 'menu'): ?>

        <h2>Main Menu</h2>
        <div class="menu-grid">
            <a class="btn" href="?view=inventory">Inventory</a>
            <a class="btn" href="?view=sell">Sell Battery</a>
            <a class="btn" href="?view=transfer">Transfer Battery</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Use the buttons above to manage batteries.
            </p>
        </div>


    <!-- ===================================================
         SECTION 11: INVENTORY VIEW
         =================================================== -->
    <?php elseif ($view === 'inventory'): ?>

        <h2>Inventory Summary</h2>
        <div class="card">
            <form method="get">
                <input type="hidden" name="view" value="inventory">

                <div class="filter-row">
                    <div style="flex:1;">
                        <label class="label-block">Location</label>
                        <select name="loc">
                            <option value="">All Locations</option>
                            <?php foreach ($allLocations as $loc): ?>
                                <option value="<?= htmlspecialchars($loc) ?>"
                                    <?= ($loc === ($selectedLocation ?? '')) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($loc) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex:1;">
                        <label class="label-block">Battery</label>
                        <select name="bat">
                            <option value="">All Batteries</option>
                            <?php foreach ($allBatteries as $bat): ?>
                                <option value="<?= htmlspecialchars($bat) ?>"
                                    <?= ($bat === ($selectedBattery ?? '')) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($bat) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="filters-actions">
                    <button type="submit" class="btn">Apply Filters</button>
                    <a class="btn btn-secondary" href="?view=inventory">Clear</a>
                    <a class="btn btn-secondary" href="?view=menu">Menu</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="table-container">
                <table>
                    <tr>
                        <th>Battery</th>
                        <th>Quantity</th>
                        <th>Location</th>
                    </tr>
                    <?php if (empty($invRows)): ?>
                        <tr>
                            <td colspan="3" class="text-center">No records found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($invRows as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['Battery'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['Quantity'] ?? '') ?></td>
                                <td><?= htmlspecialchars($r['Location'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>


    <!-- ===================================================
         SECTION 12: SELL BATTERY VIEW
         =================================================== -->
    <?php elseif ($view === 'sell'): ?>

        <h2>Sell a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($sellError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($sellError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Lookup BatteryID -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <input type="text" name="battery_id"
                       value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                       placeholder="Enter BatteryID">

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6" style="font-size:12px; color:#6b7280;">
                Only batteries not previously <strong>SOLD</strong> or <strong>SCRAPPED</strong>are eligible.
            </p>
        </div>

        <!-- Step 2: If lookup succeeded, show info + confirm button -->
        <?php if ($sellInfo): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Sale</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($sellInfo['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($sellInfo['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($sellInfo['DateCode']) ?></p>
                <p><strong>Location:</strong> <?= htmlspecialchars($sellInfo['Location']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($sellInfo['BatteryID']) ?>">
                    <button type="submit" name="confirm_sell" class="btn">
                        Sell This Battery
                    </button>
                </form>
            </div>
        <?php endif; ?>


    <!-- ===================================================
         SECTION 13: TRANSFER BATTERY VIEW
         =================================================== -->
    <?php elseif ($view === 'transfer'): ?>

        <h2>Transfer a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($transferError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($transferError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Input + Toggle + Destination -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <input type="text" name="battery_id"
                       value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                       placeholder="Enter BatteryID">

                <label class="label-block mt-10">Transfer Type</label>
                <div class="radio-row">
                    <label>
                        <input type="radio" name="mode" value="truck"
                            <?= ($transferMode === 'truck') ? 'checked' : '' ?>>
                        Truck
                    </label>
                    <label>
                        <input type="radio" name="mode" value="shop"
                            <?= ($transferMode === 'shop') ? 'checked' : '' ?>>
                        Shop
                    </label>
                </div>

                <label class="label-block mt-10">Transfer To</label>
                <?php if ($transferMode === 'shop'): ?>
                    <!-- Shop locations dropdown -->
                    <select name="to_loc">
                        <option value="">Select Location</option>
                        <?php foreach ($shopLocations as $loc): ?>
                            <option value="<?= htmlspecialchars($loc) ?>"
                                <?= ($loc === $transferToLoc) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($loc) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <!-- Truck dropdown -->
                    <select name="to_loc">
                        <option value="">Select Truck</option>
                        <?php foreach ($truckList as $truck): ?>
                            <option value="<?= htmlspecialchars($truck) ?>"
                                <?= ($truck === $transferToLoc) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($truck) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <button type="submit" name="preview_transfer" class="btn mt-10">
                    Preview Transfer
                </button>
            </form>

            <p class="mt-6" style="font-size:12px; color:#6b7280;">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be transferred.<br>
                From Location and To Location must be different.
            </p>
        </div>

        <!-- Step 2: Preview summary + confirm -->
        <?php if ($transferPreview): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Transfer</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($transferPreview['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($transferPreview['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($transferPreview['DateCode']) ?></p>
                <p><strong>From Location:</strong> <?= htmlspecialchars($transferPreview['FromLoc']) ?></p>
                <p><strong>To Location:</strong> <?= htmlspecialchars($transferPreview['ToLoc']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($transferPreview['BatteryID']) ?>">
                    <input type="hidden" name="from_loc"
                           value="<?= htmlspecialchars($transferPreview['FromLoc']) ?>">
                    <input type="hidden" name="to_loc"
                           value="<?= htmlspecialchars($transferPreview['ToLoc']) ?>">
                    <input type="hidden" name="battery"
                           value="<?= htmlspecialchars($transferPreview['Battery']) ?>">
                    <input type="hidden" name="date_code"
                           value="<?= htmlspecialchars($transferPreview['DateCode']) ?>">

                    <button type="submit" name="confirm_transfer" class="btn">
                        Confirm Transfer
                    </button>
                </form>
            </div>
        <?php endif; ?>


    <?php endif; ?>

</div>
</body>
</html>
