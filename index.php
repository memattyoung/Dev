Skip to content

You said:
Here is my code base to start
<?php
// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
    // Clear session data
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        // Session expired
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    } else {
        // Still active, refresh timer
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE (NO OUTPUT BEFORE THIS POINT) =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Please enter both AAA and Password.";
        } else {
            try {
                $dsnLogin = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
                $pdoLogin = new PDO($dsnLogin, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "
                    SELECT AAA, FirstName, LastName
                    FROM Employee
                    WHERE AAA = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA'];
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', '', 'MOBILE', :lastUpdate)
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']     = true;
                    $_SESSION['empAAA']        = $empId;
                    $_SESSION['empFirst']      = $emp['FirstName'];
                    $_SESSION['empLast']       = $emp['LastName'];
                    $_SESSION['empName']       = $empName;
                    $_SESSION['last_activity'] = time(); // start timeout timer

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }

            } catch (Exception $e) {
                $error = "Login error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Browns Towing Battery Program Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2 style="text-align:center;">Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p style="color:orange;">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>AAA:</label><br>
            <input type="text" name="aaa"
                   style="width:100%; padding:8px; margin:8px 0;"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

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

// ===== WE HAVE A LOGGED IN USER =====
$empAAA  = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName = $_SESSION['empName'] ?? 'Tuna Marie';

// ===== CONNECT TO DB =====
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE =====
// views: menu | inventory | sell | transfer | scrap | stocktruck
$view = $_GET['view'] ?? 'menu';
$msg  = $_GET['msg']  ?? '';

// Predeclare vars
$invRows        = [];
$allLocations   = [];
$allBatteries   = [];

$sellError      = "";
$sellInfo       = null;

$transferError   = "";
$transferPreview = null;
$transferToLoc   = "";
$destRows        = [];

$scrapError      = "";
$scrapInfo       = null;

$soldTodayCount  = 0;

// ===== INVENTORY SECTION =====
if ($view === 'inventory') {
    $selectedLocation = isset($_GET['loc']) ? trim($_GET['loc']) : '';
    $selectedBattery  = isset($_GET['bat']) ? trim($_GET['bat']) : '';

    // Dropdown data (NO SOLD / SCRAPPED)
    $sqlAll = "
        SELECT 
            Battery.Battery AS Battery,
            Inventory.Location AS Location
        FROM Battery
        JOIN Inventory ON Battery.BatteryID = Inventory.BatteryID
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
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

    // Aggregated query (NO SOLD / SCRAPPED)
    $sql = "
        SELECT 
            Battery.Battery AS Battery,
            COUNT(Battery.Battery) AS Quantity,
            Inventory.Location AS Location
        FROM Battery
        JOIN Inventory ON Battery.BatteryID = Inventory.BatteryID
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
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

// ===== SELL BATTERY SECTION =====
if ($view === 'sell') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup
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
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm sale
        elseif (isset($_POST['confirm_sell'])) {
            $bid = trim($_POST['battery_id'] ?? '');

            if ($bid === '') {
                $sellError = "Missing BatteryID.";
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
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $fromLoc = $row['Location'];
                        $now     = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory 
                            SET Location = 'SOLD' 
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                            VALUES
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':batteryId'  => $row['BatteryID'],
                            ':battery'    => $row['Battery'],
                            ':dateCode'   => $row['DateCode'],
                            ':lastUpdate' => $now,
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

// ===== TRANSFER BATTERY SECTION =====
if ($view === 'transfer') {
    // Build combined destination list: shops + trucks
    $stmtDest = $pdo->query("
        SELECT Location AS ToLoc, 'SHOP' AS Type
        FROM Location
        UNION ALL
        SELECT Truck AS ToLoc, 'TRUCK' AS Type
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
    ");
    $rowsDest = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($rowsDest as $r) {
        $loc  = trim($r['ToLoc'] ?? '');
        $type = $r['Type'] ?? 'TRUCK';
        if ($loc === '') continue;
        if (!isset($seen[$loc])) {
            $seen[$loc] = $type;
        }
    }

    foreach ($seen as $loc => $type) {
        $destRows[] = [
            'ToLoc' => $loc,
            'Type'  => $type
        ];
    }

    // Sort: shops first, then trucks; each group descending by name
    usort($destRows, function($a, $b) {
        if ($a['Type'] !== $b['Type']) {
            return ($a['Type'] === 'SHOP') ? -1 : 1;
        }
        return strcasecmp($b['ToLoc'], $a['ToLoc']);
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Preview transfer
        if (isset($_POST['preview_transfer'])) {
            $bid        = trim($_POST['battery_id'] ?? '');
            $transferTo = trim($_POST['to_loc'] ?? '');
            $transferToLoc = $transferTo;

            if ($bid === '') {
                $transferError = "Please enter a BatteryID.";
            } elseif ($transferTo === '') {
                $transferError = "Please select a destination.";
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
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED.";
                } else {
                    $fromLoc = $row['Location'];

                    if ($fromLoc === $transferTo) {
                        $transferError = "BatteryID already at location.";
                    } else {
                        $transferPreview = [
                            'BatteryID' => $row['BatteryID'],
                            'Battery'   => $row['Battery'],
                            'DateCode'  => $row['DateCode'],
                            'FromLoc'   => $fromLoc,
                            'ToLoc'     => $transferTo,
                        ];
                    }
                }
            }
        }

        // Step 2: Confirm transfer
        elseif (isset($_POST['confirm_transfer'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $fromLoc   = trim($_POST['from_loc'] ?? '');
            $toLoc     = trim($_POST['to_loc'] ?? '');
            $battery   = trim($_POST['battery'] ?? '');
            $dateCode  = trim($_POST['date_code'] ?? '');

            if ($bid === '' || $fromLoc === '' || $toLoc === '') {
                $transferError = "Missing transfer data. Please try again.";
            } elseif ($fromLoc === $toLoc) {
                $transferError = "BatteryID already at location.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $check = $pdo->prepare("
                        SELECT Inventory.Location
                        FROM Inventory
                        WHERE BatteryID = :bid
                          AND Location NOT IN ('SOLD','SCRAPPED')
                    ");
                    $check->execute([':bid' => $bid]);
                    $current = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$current || $current['Location'] !== $fromLoc) {
                        $pdo->rollBack();
                        $transferError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                    } else {
                        $now = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :toLoc
                            WHERE BatteryID = :bid
                        ");
                        $update->execute([
                            ':toLoc' => $toLoc,
                            ':bid'   => $bid
                        ]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':toLoc'      => $toLoc,
                            ':batteryId'  => $bid,
                            ':battery'    => $battery,
                            ':dateCode'   => $dateCode,
                            ':lastUpdate' => $now,
                        ]);

                        $pdo->commit();

                        header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=transferred");
                        exit;
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $transferError = "Error transferring battery: " . $e->getMessage();
                }
            }
        }
    }
}

// ===== SCRAP BATTERY SECTION =====
if ($view === 'scrap') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup battery
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $scrapError = "Please enter a BatteryID.";
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
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $scrapInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$scrapInfo) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm scrap
        elseif (isset($_POST['confirm_scrap'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $reasonRaw = $_POST['reason'] ?? '';

            // We’ll requery battery info for safety
            if ($bid === '') {
                $scrapError = "Missing BatteryID.";
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
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    // Validate reason
                    $reasonTrim  = trim($reasonRaw);
                    if ($reasonTrim === '') {
                        $scrapError = "Reason is required to scrap a battery.";
                        $scrapInfo  = $row; // keep display
                    } else {
                        // Clean reason: remove single quotes & limit to 255 chars
                        $reasonClean = str_replace("'", "", $reasonTrim);
                        $reasonClean = mb_substr($reasonClean, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $fromLoc = $row['Location'];
                            $now     = date('Y-m-d H:i:s');

                            // Update Inventory to SCRAPPED
                            $update = $pdo->prepare("
                                UPDATE Inventory 
                                SET Location = 'SCRAPPED'
                                WHERE BatteryID = :bid
                                  AND Location NOT IN ('SOLD','SCRAPPED')
                            ");
                            $update->execute([':bid' => $bid]);

                            // Insert AuditLog (ToLoc = SCRAPPED, Reason = user text)
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                                VALUES
                                    (:empId, :empName, :fromLoc, 'SCRAPPED', :batteryId, 'Scrap', '', :battery, :dateCode, :reason, :fromLoc, 'MOBILE', :lastUpdate)
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':batteryId'  => $row['BatteryID'],
                                ':battery'    => $row['Battery'],
                                ':dateCode'   => $row['DateCode'],
                                ':reason'     => $reasonClean,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=scrapped");
                            exit;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $scrapError = "Error scrapping battery: " . $e->getMessage();
                            $scrapInfo  = $row;
                        }
                    }
                }
            }
        }
    }
}

// ===== SOLD TODAY COUNT (MENU ONLY, BASED ON EST/EDT) =====
if ($view === 'menu') {
    try {
        // Local "today" in EST/EDT
        $startToday = date('Y-m-d 00:00:00');
        $endToday   = date('Y-m-d 23:59:59');

        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND LastUpdate >= :startToday
              AND LastUpdate <= :endToday
        ");
        $stmtSold->execute([
            ':empId'      => $empAAA,
            ':startToday' => $startToday,
            ':endToday'   => $endToday,
        ]);
        $soldTodayCount = (int)$stmtSold->fetchColumn();
    } catch (Exception $e) {
        $soldTodayCount = 0; // fail quietly
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
                grid-template-columns: repeat(4, 1fr);
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
        select, input[type="text"], textarea {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        textarea {
            min-height: 80px;
            resize: vertical;
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
        .mt-6  { margin-top: 6px; }
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php elseif ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php elseif ($msg === 'scrapped'): ?>
        <div class="msg msg-success">
            Battery was successfully scrapped and logged.
        </div>
    <?php endif; ?>

    <?php if ($view === 'menu'): ?>

        <h2>Main Menu</h2>
        <div class="menu-grid">
            <a class="btn" href="?view=inventory">Inventory</a>
            <a class="btn" href="?view=sell">Sell Battery</a>
            <a class="btn" href="?view=stocktruck">Stock Truck</a>
            <a class="btn" href="?view=transfer">Transfer Battery</a>
            <a class="btn" href="?view=scrap">Scrap Battery</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong> (<?= htmlspecialchars($empAAA) ?>).<br>
                Use the buttons above to manage batteries.
            </p>

            <?php if ($soldTodayCount > 0): ?>
                <p class="text-center" style="font-size:13px; color:#166534; margin-top:6px;">
                    You have sold <strong><?= $soldTodayCount ?></strong>
                    battery<?= ($soldTodayCount === 1 ? '' : 'ies') ?> so far today.
                </p>
            <?php endif; ?>

            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary" style="max-width:200px; display:inline-block;">
                    Logout
                </a>
            </div>
        </div>

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

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="sell_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('sell_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not previously <strong>SOLD</strong> or <strong>SCRAPPED</strong> are eligible.
            </p>
        </div>

        <!-- Step 2: Confirm Sale -->
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

        <!-- Step 1: BatteryID + Destination -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="transfer_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('transfer_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <label class="label-block mt-10">Transfer To</label>
                <select name="to_loc">
                    <option value="">Select Destination</option>
                    <?php foreach ($destRows as $d): ?>
                        <?php
                        $label = $d['ToLoc'] . ' (' . $d['Type'] . ')';
                        $val   = $d['ToLoc'];
                        ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                            <?= ($val === ($transferToLoc ?? '')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="preview_transfer" class="btn mt-10">
                    Preview Transfer
                </button>
            </form>

            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be transferred.
            </p>
        </div>

        <!-- Step 2: Preview + Confirm -->
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

    <?php elseif ($view === 'scrap'): ?>

        <h2>Scrap a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($scrapError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($scrapError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="scrap_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('scrap_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be scrapped.
            </p>
        </div>

        <!-- Step 2: Confirm Scrap + Reason -->
        <?php if ($scrapInfo): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Scrap</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($scrapInfo['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($scrapInfo['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($scrapInfo['DateCode']) ?></p>
                <p><strong>Current Location:</strong> <?= htmlspecialchars($scrapInfo['Location']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($scrapInfo['BatteryID']) ?>">

                    <label class="label-block">Reason for Scrap (required, max 255 chars)</label>
                    <textarea name="reason" maxlength="255"
                              placeholder="Describe why this battery is being scrapped."><?= isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : '' ?></textarea>

                    <button type="submit" name="confirm_scrap" class="btn mt-10">
                        Scrap This Battery
                    </button>
                </form>

                <p class="mt-6 small-note">
                    Reason text will be cleaned (for example, single quotes removed) before being stored in the audit log.
                </p>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'stocktruck'): ?>

        <h2>Stock Truck</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:14px;">
                Stock Truck functionality coming soon.
            </p>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Barcode Scanner Modal -->
<div id="scannerOverlay" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.8);
    z-index:9999;
    align-items:center;
    justify-content:center;
">
    <div style="background:#fff; padding:10px; border-radius:8px; max-width:400px; width:90%; text-align:center;">
        <h3 style="margin-top:0;">Scan Battery Barcode</h3>
        <video id="scannerVideo" style="width:100%; max-height:300px; background:#000;"></video>
        <p style="font-size:12px; color:#6b7280; margin-top:6px;">
            Align the barcode within the frame until it is detected.
        </p>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:8px;">
            <button type="button" onclick="switchCamera()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#2563eb;
                color:#fff;
            ">
                Switch Camera
            </button>
            <button type="button" onclick="closeScanner()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#4b5563;
                color:#fff;
            ">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ZXing and scanner script -->
<script src="https://unpkg.com/@zxing/library@latest"></script>
<script>
    let selectedInputId = null;
    let codeReader = null;
    let videoInputDevices = [];
    let currentDeviceIndex = 0;
    let currentStream = null;
    let audioCtx = null;

    function playBeep() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
            }
            const duration = 0.15; // seconds
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5-ish
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    }

    function stopCurrentStream() {
        const video = document.getElementById('scannerVideo');
        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }
        if (video) {
            video.srcObject = null;
        }
    }

    async function startDecodingWithCurrentDevice() {
        if (!videoInputDevices.length) return;

        const device = videoInputDevices[currentDeviceIndex];
        const deviceId = device.deviceId;

        stopCurrentStream();

        try {
            const video = document.getElementById('scannerVideo');

            // ZXing will call getUserMedia under the hood
            codeReader.decodeFromVideoDevice(deviceId, 'scannerVideo', (result, err) => {
                if (result) {
                    const input = document.getElementById(selectedInputId);
                    if (input) {
                        input.value = result.text;
                    }
                    playBeep();
                    closeScanner();
                }
                // err is usually "NotFoundException" while scanning – safe to ignore
            });

            // Save the stream from the video element once it starts
            setTimeout(() => {
                if (video && video.srcObject) {
                    currentStream = video.srcObject;
                }
            }, 500);

        } catch (e) {
            console.error('Error starting decode:', e);
            alert('Unable to start camera. Please check permissions.');
            closeScanner();
        }
    }

    function pickBackCameraIndex(devices) {
        // Prefer labels that look like back/rear/environment
        let idx = devices.findIndex(d =>
            /back|rear|environment/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Next, prefer anything that is NOT clearly front
        idx = devices.findIndex(d =>
            !/front|user/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Fallback to first
        return 0;
    }

    async function openScanner(inputId) {
        selectedInputId = inputId;
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'flex';

        if (!codeReader) {
            codeReader = new ZXing.BrowserMultiFormatReader();
        }

        try {
            // Get list of cameras
            videoInputDevices = await codeReader.listVideoInputDevices();
            if (!videoInputDevices.length) {
                alert('No camera found on this device.');
                closeScanner();
                return;
            }

            currentDeviceIndex = pickBackCameraIndex(videoInputDevices);
            await startDecodingWithCurrentDevice();
        } catch (e) {
            console.error(e);
            alert('Unable to access camera. Please check permissions.');
            closeScanner();
        }
    }

    function switchCamera() {
        if (!videoInputDevices.length || !codeReader) return;

        try {
            codeReader.reset();
        } catch (e) {
            console.warn(e);
        }
        stopCurrentStream();

        currentDeviceIndex = (currentDeviceIndex + 1) % videoInputDevices.length;
        startDecodingWithCurrentDevice();
    }

    function closeScanner() {
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'none';

        if (codeReader) {
            try {
                codeReader.reset();
            } catch (e) {
                console.warn(e);
            }
        }
        stopCurrentStream();
    }
</script>
</body>
</html>
ChatGPT said:

Thought for 12s
Nice, thanks for dropping the full code in here — this is a solid starting point.

Right now, your app has:

Login with AAA + Password, tied to Employee table

AuditLog entries on Log On, Sell, Transfer, and Scrap, all with LastUpdate using EST/EDT

Session timeout + logout button

Views wired up for:

menu

inventory

sell

transfer

scrap

stocktruck → currently just “coming soon”

I see you’ve already got:

Logout handled via ?logout=1

Sold-today count on the main menu

Barcode scanning with ZXing and a beep on success

Scrap flow with required, cleaned free-text Reason

Before I start changing anything, what do you want to tackle next in this code base?

Some likely options based on what we’ve talked about before:

Implement “Stock Truck” logic

Pick a truck from a list

Scan multiple BatteryIDs and move them from a warehouse/shop to that truck

Show a confirmation/summary before commit

Write matching AuditLog entries

Add an Inventory detail view

Tap a row in Inventory → see all BatteryIDs at that location / battery type

Add basic reporting

E.g., “batteries sold by user today/this week”

“Inventory on each truck/shop”

Tighten security / validation

Password hashing instead of plain text

Extra checks on inputs, etc.

Tell me exactly what you want “Stock Truck” (or anything else) to do step-by-step, and I’ll wire it directly into your existing code so you can paste it in and go.

You said:
I eliminated the Battery table and combined it with the inventory table. can you please update the selects? All the field names stayed the same, they were just moved to the inventory table. In the inventory table we need to make sure that for all the StockType = 'BATTERY' for every query calling the inventory table
ChatGPT said:

Thought for 58s
Gotcha. Since Battery is gone and those fields now live on Inventory, we’ll:

Remove all JOIN Battery usage and select directly from Inventory.

Add AND StockType = 'BATTERY' to every query that touches Inventory (SELECT, UPDATE, etc.), so we never accidentally hit other stock types.

Here’s your updated PHP file with those changes applied (drop-in replacement):

<?php
// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
    // Clear session data
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        // Session expired
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    } else {
        // Still active, refresh timer
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE (NO OUTPUT BEFORE THIS POINT) =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Please enter both AAA and Password.";
        } else {
            try {
                $dsnLogin = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
                $pdoLogin = new PDO($dsnLogin, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "
                    SELECT AAA, FirstName, LastName
                    FROM Employee
                    WHERE AAA = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA'];
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', '', 'MOBILE', :lastUpdate)
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']     = true;
                    $_SESSION['empAAA']        = $empId;
                    $_SESSION['empFirst']      = $emp['FirstName'];
                    $_SESSION['empLast']       = $emp['LastName'];
                    $_SESSION['empName']       = $empName;
                    $_SESSION['last_activity'] = time(); // start timeout timer

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }

            } catch (Exception $e) {
                $error = "Login error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Browns Towing Battery Program Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2 style="text-align:center;">Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p style="color:orange;">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>AAA:</label><br>
            <input type="text" name="aaa"
                   style="width:100%; padding:8px; margin:8px 0;"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

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

// ===== WE HAVE A LOGGED IN USER =====
$empAAA  = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName = $_SESSION['empName'] ?? 'Tuna Marie';

// ===== CONNECT TO DB =====
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE =====
// views: menu | inventory | sell | transfer | scrap | stocktruck
$view = $_GET['view'] ?? 'menu';
$msg  = $_GET['msg']  ?? '';

// Predeclare vars
$invRows        = [];
$allLocations   = [];
$allBatteries   = [];

$sellError      = "";
$sellInfo       = null;

$transferError   = "";
$transferPreview = null;
$transferToLoc   = "";
$destRows        = [];

$scrapError      = "";
$scrapInfo       = null;

$soldTodayCount  = 0;

// ===== INVENTORY SECTION =====
if ($view === 'inventory') {
    $selectedLocation = isset($_GET['loc']) ? trim($_GET['loc']) : '';
    $selectedBattery  = isset($_GET['bat']) ? trim($_GET['bat']) : '';

    // Dropdown data (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sqlAll = "
        SELECT 
            Inventory.Battery AS Battery,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
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

    // Aggregated query (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sql = "
        SELECT 
            Inventory.Battery AS Battery,
            COUNT(*) AS Quantity,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
    ";
    $params = [];

    if ($selectedLocation !== '') {
        $sql .= " AND Inventory.Location = :loc";
        $params[':loc'] = $selectedLocation;
    }
    if ($selectedBattery !== '') {
        $sql .= " AND Inventory.Battery = :bat";
        $params[':bat'] = $selectedBattery;
    }

    $sql .= "
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== SELL BATTERY SECTION =====
if ($view === 'sell') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $sellError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm sale
        elseif (isset($_POST['confirm_sell'])) {
            $bid = trim($_POST['battery_id'] ?? '');

            if ($bid === '') {
                $sellError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $fromLoc = $row['Location'];
                        $now     = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory 
                            SET Location = 'SOLD' 
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                            VALUES
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':batteryId'  => $row['BatteryID'],
                            ':battery'    => $row['Battery'],
                            ':dateCode'   => $row['DateCode'],
                            ':lastUpdate' => $now,
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

// ===== TRANSFER BATTERY SECTION =====
if ($view === 'transfer') {
    // Build combined destination list: shops + trucks
    $stmtDest = $pdo->query("
        SELECT Location AS ToLoc, 'SHOP' AS Type
        FROM Location
        UNION ALL
        SELECT Truck AS ToLoc, 'TRUCK' AS Type
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
    ");
    $rowsDest = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($rowsDest as $r) {
        $loc  = trim($r['ToLoc'] ?? '');
        $type = $r['Type'] ?? 'TRUCK';
        if ($loc === '') continue;
        if (!isset($seen[$loc])) {
            $seen[$loc] = $type;
        }
    }

    foreach ($seen as $loc => $type) {
        $destRows[] = [
            'ToLoc' => $loc,
            'Type'  => $type
        ];
    }

    // Sort: shops first, then trucks; each group descending by name
    usort($destRows, function($a, $b) {
        if ($a['Type'] !== $b['Type']) {
            return ($a['Type'] === 'SHOP') ? -1 : 1;
        }
        return strcasecmp($b['ToLoc'], $a['ToLoc']);
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Preview transfer
        if (isset($_POST['preview_transfer'])) {
            $bid        = trim($_POST['battery_id'] ?? '');
            $transferTo = trim($_POST['to_loc'] ?? '');
            $transferToLoc = $transferTo;

            if ($bid === '') {
                $transferError = "Please enter a BatteryID.";
            } elseif ($transferTo === '') {
                $transferError = "Please select a destination.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED.";
                } else {
                    $fromLoc = $row['Location'];

                    if ($fromLoc === $transferTo) {
                        $transferError = "BatteryID already at location.";
                    } else {
                        $transferPreview = [
                            'BatteryID' => $row['BatteryID'],
                            'Battery'   => $row['Battery'],
                            'DateCode'  => $row['DateCode'],
                            'FromLoc'   => $fromLoc,
                            'ToLoc'     => $transferTo,
                        ];
                    }
                }
            }
        }

        // Step 2: Confirm transfer
        elseif (isset($_POST['confirm_transfer'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $fromLoc   = trim($_POST['from_loc'] ?? '');
            $toLoc     = trim($_POST['to_loc'] ?? '');
            $battery   = trim($_POST['battery'] ?? '');
            $dateCode  = trim($_POST['date_code'] ?? '');

            if ($bid === '' || $fromLoc === '' || $toLoc === '') {
                $transferError = "Missing transfer data. Please try again.";
            } elseif ($fromLoc === $toLoc) {
                $transferError = "BatteryID already at location.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $check = $pdo->prepare("
                        SELECT Inventory.Location
                        FROM Inventory
                        WHERE BatteryID = :bid
                          AND Location NOT IN ('SOLD','SCRAPPED')
                          AND StockType = 'BATTERY'
                    ");
                    $check->execute([':bid' => $bid]);
                    $current = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$current || $current['Location'] !== $fromLoc) {
                        $pdo->rollBack();
                        $transferError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                    } else {
                        $now = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :toLoc
                            WHERE BatteryID = :bid
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([
                            ':toLoc' => $toLoc,
                            ':bid'   => $bid
                        ]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':toLoc'      => $toLoc,
                            ':batteryId'  => $bid,
                            ':battery'    => $battery,
                            ':dateCode'   => $dateCode,
                            ':lastUpdate' => $now,
                        ]);

                        $pdo->commit();

                        header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=transferred");
                        exit;
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $transferError = "Error transferring battery: " . $e->getMessage();
                }
            }
        }
    }
}

// ===== SCRAP BATTERY SECTION =====
if ($view === 'scrap') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup battery
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $scrapError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $scrapInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$scrapInfo) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm scrap
        elseif (isset($_POST['confirm_scrap'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $reasonRaw = $_POST['reason'] ?? '';

            // We’ll requery battery info for safety
            if ($bid === '') {
                $scrapError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    // Validate reason
                    $reasonTrim  = trim($reasonRaw);
                    if ($reasonTrim === '') {
                        $scrapError = "Reason is required to scrap a battery.";
                        $scrapInfo  = $row; // keep display
                    } else {
                        // Clean reason: remove single quotes & limit to 255 chars
                        $reasonClean = str_replace("'", "", $reasonTrim);
                        $reasonClean = mb_substr($reasonClean, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $fromLoc = $row['Location'];
                            $now     = date('Y-m-d H:i:s');

                            // Update Inventory to SCRAPPED
                            $update = $pdo->prepare("
                                UPDATE Inventory 
                                SET Location = 'SCRAPPED'
                                WHERE BatteryID = :bid
                                  AND Location NOT IN ('SOLD','SCRAPPED')
                                  AND StockType = 'BATTERY'
                            ");
                            $update->execute([':bid' => $bid]);

                            // Insert AuditLog (ToLoc = SCRAPPED, Reason = user text)
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                                VALUES
                                    (:empId, :empName, :fromLoc, 'SCRAPPED', :batteryId, 'Scrap', '', :battery, :dateCode, :reason, :fromLoc, 'MOBILE', :lastUpdate)
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':batteryId'  => $row['BatteryID'],
                                ':battery'    => $row['Battery'],
                                ':dateCode'   => $row['DateCode'],
                                ':reason'     => $reasonClean,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=scrapped");
                            exit;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $scrapError = "Error scrapping battery: " . $e->getMessage();
                            $scrapInfo  = $row;
                        }
                    }
                }
            }
        }
    }
}

// ===== SOLD TODAY COUNT (MENU ONLY, BASED ON EST/EDT) =====
if ($view === 'menu') {
    try {
        // Local "today" in EST/EDT
        $startToday = date('Y-m-d 00:00:00');
        $endToday   = date('Y-m-d 23:59:59');

        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND LastUpdate >= :startToday
              AND LastUpdate <= :endToday
        ");
        $stmtSold->execute([
            ':empId'      => $empAAA,
            ':startToday' => $startToday,
            ':endToday'   => $endToday,
        ]);
        $soldTodayCount = (int)$stmtSold->fetchColumn();
    } catch (Exception $e) {
        $soldTodayCount = 0; // fail quietly
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
                grid-template-columns: repeat(4, 1fr);
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
        select, input[type="text"], textarea {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        textarea {
            min-height: 80px;
            resize: vertical;
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
        .mt-6  { margin-top: 6px; }
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php elseif ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php elseif ($msg === 'scrapped'): ?>
        <div class="msg msg-success">
            Battery was successfully scrapped and logged.
        </div>
    <?php endif; ?>

    <?php if ($view === 'menu'): ?>

        <h2>Main Menu</h2>
        <div class="menu-grid">
            <a class="btn" href="?view=inventory">Inventory</a>
            <a class="btn" href="?view=sell">Sell Battery</a>
            <a class="btn" href="?view=stocktruck">Stock Truck</a>
            <a class="btn" href="?view=transfer">Transfer Battery</a>
            <a class="btn" href="?view=scrap">Scrap Battery</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong> (<?= htmlspecialchars($empAAA) ?>).<br>
                Use the buttons above to manage batteries.
            </p>

            <?php if ($soldTodayCount > 0): ?>
                <p class="text-center" style="font-size:13px; color:#166534; margin-top:6px;">
                    You have sold <strong><?= $soldTodayCount ?></strong>
                    battery<?= ($soldTodayCount === 1 ? '' : 'ies') ?> so far today.
                </p>
            <?php endif; ?>

            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary" style="max-width:200px; display:inline-block;">
                    Logout
                </a>
            </div>
        </div>

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

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="sell_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('sell_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not previously <strong>SOLD</strong> or <strong>SCRAPPED</strong> are eligible.
            </p>
        </div>

        <!-- Step 2: Confirm Sale -->
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

        <!-- Step 1: BatteryID + Destination -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="transfer_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('transfer_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <label class="label-block mt-10">Transfer To</label>
                <select name="to_loc">
                    <option value="">Select Destination</option>
                    <?php foreach ($destRows as $d): ?>
                        <?php
                        $label = $d['ToLoc'] . ' (' . $d['Type'] . ')';
                        $val   = $d['ToLoc'];
                        ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                            <?= ($val === ($transferToLoc ?? '')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="preview_transfer" class="btn mt-10">
                    Preview Transfer
                </button>
            </form>

            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be transferred.
            </p>
        </div>

        <!-- Step 2: Preview + Confirm -->
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

    <?php elseif ($view === 'scrap'): ?>

        <h2>Scrap a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($scrapError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($scrapError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="scrap_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('scrap_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be scrapped.
            </p>
        </div>

        <!-- Step 2: Confirm Scrap + Reason -->
        <?php if ($scrapInfo): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Scrap</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($scrapInfo['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($scrapInfo['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($scrapInfo['DateCode']) ?></p>
                <p><strong>Current Location:</strong> <?= htmlspecialchars($scrapInfo['Location']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($scrapInfo['BatteryID']) ?>">

                    <label class="label-block">Reason for Scrap (required, max 255 chars)</label>
                    <textarea name="reason" maxlength="255"
                              placeholder="Describe why this battery is being scrapped."><?= isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : '' ?></textarea>

                    <button type="submit" name="confirm_scrap" class="btn mt-10">
                        Scrap This Battery
                    </button>
                </form>

                <p class="mt-6 small-note">
                    Reason text will be cleaned (for example, single quotes removed) before being stored in the audit log.
                </p>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'stocktruck'): ?>

        <h2>Stock Truck</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:14px;">
                Stock Truck functionality coming soon.
            </p>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Barcode Scanner Modal -->
<div id="scannerOverlay" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.8);
    z-index:9999;
    align-items:center;
    justify-content:center;
">
    <div style="background:#fff; padding:10px; border-radius:8px; max-width:400px; width:90%; text-align:center;">
        <h3 style="margin-top:0;">Scan Battery Barcode</h3>
        <video id="scannerVideo" style="width:100%; max-height:300px; background:#000;"></video>
        <p style="font-size:12px; color:#6b7280; margin-top:6px;">
            Align the barcode within the frame until it is detected.
        </p>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:8px;">
            <button type="button" onclick="switchCamera()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#2563eb;
                color:#fff;
            ">
                Switch Camera
            </button>
            <button type="button" onclick="closeScanner()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#4b5563;
                color:#fff;
            ">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ZXing and scanner script -->
<script src="https://unpkg.com/@zxing/library@latest"></script>
<script>
    let selectedInputId = null;
    let codeReader = null;
    let videoInputDevices = [];
    let currentDeviceIndex = 0;
    let currentStream = null;
    let audioCtx = null;

    function playBeep() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
            }
            const duration = 0.15; // seconds
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5-ish
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    }

    function stopCurrentStream() {
        const video = document.getElementById('scannerVideo');
        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }
        if (video) {
            video.srcObject = null;
        }
    }

    async function startDecodingWithCurrentDevice() {
        if (!videoInputDevices.length) return;

        const device = videoInputDevices[currentDeviceIndex];
        const deviceId = device.deviceId;

        stopCurrentStream();

        try {
            const video = document.getElementById('scannerVideo');

            // ZXing will call getUserMedia under the hood
            codeReader.decodeFromVideoDevice(deviceId, 'scannerVideo', (result, err) => {
                if (result) {
                    const input = document.getElementById(selectedInputId);
                    if (input) {
                        input.value = result.text;
                    }
                    playBeep();
                    closeScanner();
                }
                // err is usually "NotFoundException" while scanning – safe to ignore
            });

            // Save the stream from the video element once it starts
            setTimeout(() => {
                if (video && video.srcObject) {
                    currentStream = video.srcObject;
                }
            }, 500);

        } catch (e) {
            console.error('Error starting decode:', e);
            alert('Unable to start camera. Please check permissions.');
            closeScanner();
        }
    }

    function pickBackCameraIndex(devices) {
        // Prefer labels that look like back/rear/environment
        let idx = devices.findIndex(d =>
            /back|rear|environment/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Next, prefer anything that is NOT clearly front
        idx = devices.findIndex(d =>
            !/front|user/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Fallback to first
        return 0;
    }

    async function openScanner(inputId) {
        selectedInputId = inputId;
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'flex';

        if (!codeReader) {
            codeReader = new ZXing.BrowserMultiFormatReader();
        }

        try {
            // Get list of cameras
            videoInputDevices = await codeReader.listVideoInputDevices();
            if (!videoInputDevices.length) {
                alert('No camera found on this device.');
                closeScanner();
                return;
            }

            currentDeviceIndex = pickBackCameraIndex(videoInputDevices);
            await startDecodingWithCurrentDevice();
        } catch (e) {
            console.error(e);
            alert('Unable to access camera. Please check permissions.');
            closeScanner();
        }
    }

    function switchCamera() {
        if (!videoInputDevices.length || !codeReader) return;

        try {
            codeReader.reset();
        } catch (e) {
            console.warn(e);
        }
        stopCurrentStream();

        currentDeviceIndex = (currentDeviceIndex + 1) % videoInputDevices.length;
        startDecodingWithCurrentDevice();
    }

    function closeScanner() {
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'none';

        if (codeReader) {
            try {
                codeReader.reset();
            } catch (e) {
                console.warn(e);
            }
        }
        stopCurrentStream();
    }
</script>
</body>
</html>
If you want, next step we can add a quick sanity-check query you can run in phpMyAdmin / Workbench to verify there are no Inventory rows with StockType != 'BATTERY' that still have non-null BatteryID or Battery, just to keep the data model clean.

You said:
When doing the transfer battery, can we stay there and not back up to the main menu after a transfer? Sometimes they will have to transfer several and it easier to stay there.
ChatGPT said:

Thought for 28s
Yep, easy tweak 👍
Right now the transfer flow does a redirect back to the menu after a successful transfer. We’ll keep the user on the Transfer screen instead and just show a success message there.

Here’s what to change.

1️⃣ Add a success message variable
Near the top where you define state:

$transferError   = "";
$transferPreview = null;
$transferToLoc   = "";
$destRows        = [];
Change to:

$transferError    = "";
$transferSuccess  = "";
$transferPreview  = null;
$transferToLoc    = "";
$destRows         = [];
2️⃣ Update the “confirm transfer” success path
Find this block in the confirm_transfer section:

$pdo->commit();

header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=transferred");
exit;
Replace it with:

$pdo->commit();

// Stay on the Transfer page and show a success message
$transferSuccess = "Battery was successfully transferred and logged.";
$transferPreview = null;   // clear the preview
// keep $transferToLoc so the destination stays selected
So the full confirm_transfer success chunk looks like:

// Insert AuditLog
$insert = $pdo->prepare("
    INSERT INTO AuditLog
        (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
    VALUES
        (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate)
");
$insert->execute([
    ':empId'      => $empAAA,
    ':empName'    => $empName,
    ':fromLoc'    => $fromLoc,
    ':toLoc'      => $toLoc,
    ':batteryId'  => $bid,
    ':battery'    => $battery,
    ':dateCode'   => $dateCode,
    ':lastUpdate' => $now,
]);

$pdo->commit();

// NEW: stay on Transfer screen
$transferSuccess = "Battery was successfully transferred and logged.";
$transferPreview = null;
3️⃣ Show the success message in the Transfer view
In the Transfer view HTML, you already have this:

<?php if (!empty($transferError)): ?>
    <div class="card msg msg-error">
        <?= htmlspecialchars($transferError) ?>
    </div>
<?php endif; ?>
Right after that, add:

<?php if (!empty($transferSuccess)): ?>
    <div class="card msg msg-success">
        <?= htmlspecialchars($transferSuccess) ?>
    </div>
<?php endif; ?>
With those three changes:

After a successful transfer, the user stays on Transfer a Battery

They see a green “Battery was successfully transferred and logged” message

The destination stays selected so they can keep scanning/transferring to the same place

If you’d like, next we can do the same thing for Sell and Scrap so they stay on those screens as well instead of bouncing back to the menu.

You said:
No thanks, they will only be doing one at a time there. Those are perfect. Can you give me the full code build?
ChatGPT said:

Thought for 40s
Here’s the full updated build with:

Battery table fully merged into Inventory (all selects use Inventory only)

Every Inventory query filtered with StockType = 'BATTERY'

Transfer Battery stays on the transfer screen after a successful transfer and shows a success message (no redirect to menu)

<?php
// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
    // Clear session data
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        // Session expired
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    } else {
        // Still active, refresh timer
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE (NO OUTPUT BEFORE THIS POINT) =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Please enter both AAA and Password.";
        } else {
            try {
                $dsnLogin = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
                $pdoLogin = new PDO($dsnLogin, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "
                    SELECT AAA, FirstName, LastName
                    FROM Employee
                    WHERE AAA = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA'];
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', '', 'MOBILE', :lastUpdate)
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']     = true;
                    $_SESSION['empAAA']        = $empId;
                    $_SESSION['empFirst']      = $emp['FirstName'];
                    $_SESSION['empLast']       = $emp['LastName'];
                    $_SESSION['empName']       = $empName;
                    $_SESSION['last_activity'] = time(); // start timeout timer

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }

            } catch (Exception $e) {
                $error = "Login error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Browns Towing Battery Program Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2 style="text-align:center;">Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p style="color:orange;">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>AAA:</label><br>
            <input type="text" name="aaa"
                   style="width:100%; padding:8px; margin:8px 0;"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

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

// ===== WE HAVE A LOGGED IN USER =====
$empAAA  = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName = $_SESSION['empName'] ?? 'Tuna Marie';

// ===== CONNECT TO DB =====
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE =====
// views: menu | inventory | sell | transfer | scrap | stocktruck
$view = $_GET['view'] ?? 'menu';
$msg  = $_GET['msg']  ?? '';

// Predeclare vars
$invRows        = [];
$allLocations   = [];
$allBatteries   = [];

$sellError      = "";
$sellInfo       = null;

$transferError    = "";
$transferSuccess  = "";
$transferPreview  = null;
$transferToLoc    = "";
$destRows         = [];

$scrapError      = "";
$scrapInfo       = null;

$soldTodayCount  = 0;

// ===== INVENTORY SECTION =====
if ($view === 'inventory') {
    $selectedLocation = isset($_GET['loc']) ? trim($_GET['loc']) : '';
    $selectedBattery  = isset($_GET['bat']) ? trim($_GET['bat']) : '';

    // Dropdown data (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sqlAll = "
        SELECT 
            Inventory.Battery AS Battery,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
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

    // Aggregated query (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sql = "
        SELECT 
            Inventory.Battery AS Battery,
            COUNT(*) AS Quantity,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
    ";
    $params = [];

    if ($selectedLocation !== '') {
        $sql .= " AND Inventory.Location = :loc";
        $params[':loc'] = $selectedLocation;
    }
    if ($selectedBattery !== '') {
        $sql .= " AND Inventory.Battery = :bat";
        $params[':bat'] = $selectedBattery;
    }

    $sql .= "
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== SELL BATTERY SECTION =====
if ($view === 'sell') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $sellError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm sale
        elseif (isset($_POST['confirm_sell'])) {
            $bid = trim($_POST['battery_id'] ?? '');

            if ($bid === '') {
                $sellError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $fromLoc = $row['Location'];
                        $now     = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory 
                            SET Location = 'SOLD' 
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                            VALUES
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':batteryId'  => $row['BatteryID'],
                            ':battery'    => $row['Battery'],
                            ':dateCode'   => $row['DateCode'],
                            ':lastUpdate' => $now,
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

// ===== TRANSFER BATTERY SECTION =====
if ($view === 'transfer') {
    // Build combined destination list: shops + trucks
    $stmtDest = $pdo->query("
        SELECT Location AS ToLoc, 'SHOP' AS Type
        FROM Location
        UNION ALL
        SELECT Truck AS ToLoc, 'TRUCK' AS Type
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
    ");
    $rowsDest = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($rowsDest as $r) {
        $loc  = trim($r['ToLoc'] ?? '');
        $type = $r['Type'] ?? 'TRUCK';
        if ($loc === '') continue;
        if (!isset($seen[$loc])) {
            $seen[$loc] = $type;
        }
    }

    foreach ($seen as $loc => $type) {
        $destRows[] = [
            'ToLoc' => $loc,
            'Type'  => $type
        ];
    }

    // Sort: shops first, then trucks; each group descending by name
    usort($destRows, function($a, $b) {
        if ($a['Type'] !== $b['Type']) {
            return ($a['Type'] === 'SHOP') ? -1 : 1;
        }
        return strcasecmp($b['ToLoc'], $a['ToLoc']);
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Preview transfer
        if (isset($_POST['preview_transfer'])) {
            $bid        = trim($_POST['battery_id'] ?? '');
            $transferTo = trim($_POST['to_loc'] ?? '');
            $transferToLoc = $transferTo;

            if ($bid === '') {
                $transferError = "Please enter a BatteryID.";
            } elseif ($transferTo === '') {
                $transferError = "Please select a destination.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED.";
                } else {
                    $fromLoc = $row['Location'];

                    if ($fromLoc === $transferTo) {
                        $transferError = "BatteryID already at location.";
                    } else {
                        $transferPreview = [
                            'BatteryID' => $row['BatteryID'],
                            'Battery'   => $row['Battery'],
                            'DateCode'  => $row['DateCode'],
                            'FromLoc'   => $fromLoc,
                            'ToLoc'     => $transferTo,
                        ];
                    }
                }
            }
        }

        // Step 2: Confirm transfer
        elseif (isset($_POST['confirm_transfer'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $fromLoc   = trim($_POST['from_loc'] ?? '');
            $toLoc     = trim($_POST['to_loc'] ?? '');
            $battery   = trim($_POST['battery'] ?? '');
            $dateCode  = trim($_POST['date_code'] ?? '');

            // Keep selected dest in UI after post
            $transferToLoc = $toLoc;

            if ($bid === '' || $fromLoc === '' || $toLoc === '') {
                $transferError = "Missing transfer data. Please try again.";
            } elseif ($fromLoc === $toLoc) {
                $transferError = "BatteryID already at location.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $check = $pdo->prepare("
                        SELECT Inventory.Location
                        FROM Inventory
                        WHERE BatteryID = :bid
                          AND Location NOT IN ('SOLD','SCRAPPED')
                          AND StockType = 'BATTERY'
                    ");
                    $check->execute([':bid' => $bid]);
                    $current = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$current || $current['Location'] !== $fromLoc) {
                        $pdo->rollBack();
                        $transferError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                    } else {
                        $now = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :toLoc
                            WHERE BatteryID = :bid
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([
                            ':toLoc' => $toLoc,
                            ':bid'   => $bid
                        ]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':toLoc'      => $toLoc,
                            ':batteryId'  => $bid,
                            ':battery'    => $battery,
                            ':dateCode'   => $dateCode,
                            ':lastUpdate' => $now,
                        ]);

                        $pdo->commit();

                        // Stay on Transfer page and show success
                        $transferSuccess = "Battery was successfully transferred and logged.";
                        $transferPreview = null;
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $transferError = "Error transferring battery: " . $e->getMessage();
                }
            }
        }
    }
}

// ===== SCRAP BATTERY SECTION =====
if ($view === 'scrap') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup battery
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $scrapError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $scrapInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$scrapInfo) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm scrap
        elseif (isset($_POST['confirm_scrap'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $reasonRaw = $_POST['reason'] ?? '';

            // We’ll requery battery info for safety
            if ($bid === '') {
                $scrapError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    // Validate reason
                    $reasonTrim  = trim($reasonRaw);
                    if ($reasonTrim === '') {
                        $scrapError = "Reason is required to scrap a battery.";
                        $scrapInfo  = $row; // keep display
                    } else {
                        // Clean reason: remove single quotes & limit to 255 chars
                        $reasonClean = str_replace("'", "", $reasonTrim);
                        $reasonClean = mb_substr($reasonClean, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $fromLoc = $row['Location'];
                            $now     = date('Y-m-d H:i:s');

                            // Update Inventory to SCRAPPED
                            $update = $pdo->prepare("
                                UPDATE Inventory 
                                SET Location = 'SCRAPPED'
                                WHERE BatteryID = :bid
                                  AND Location NOT IN ('SOLD','SCRAPPED')
                                  AND StockType = 'BATTERY'
                            ");
                            $update->execute([':bid' => $bid]);

                            // Insert AuditLog (ToLoc = SCRAPPED, Reason = user text)
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                                VALUES
                                    (:empId, :empName, :fromLoc, 'SCRAPPED', :batteryId, 'Scrap', '', :battery, :dateCode, :reason, :fromLoc, 'MOBILE', :lastUpdate)
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':batteryId'  => $row['BatteryID'],
                                ':battery'    => $row['Battery'],
                                ':dateCode'   => $row['DateCode'],
                                ':reason'     => $reasonClean,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=scrapped");
                            exit;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $scrapError = "Error scrapping battery: " . $e->getMessage();
                            $scrapInfo  = $row;
                        }
                    }
                }
            }
        }
    }
}

// ===== SOLD TODAY COUNT (MENU ONLY, BASED ON EST/EDT) =====
if ($view === 'menu') {
    try {
        // Local "today" in EST/EDT
        $startToday = date('Y-m-d 00:00:00');
        $endToday   = date('Y-m-d 23:59:59');

        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND LastUpdate >= :startToday
              AND LastUpdate <= :endToday
        ");
        $stmtSold->execute([
            ':empId'      => $empAAA,
            ':startToday' => $startToday,
            ':endToday'   => $endToday,
        ]);
        $soldTodayCount = (int)$stmtSold->fetchColumn();
    } catch (Exception $e) {
        $soldTodayCount = 0; // fail quietly
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
                grid-template-columns: repeat(4, 1fr);
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
        select, input[type="text"], textarea {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        textarea {
            min-height: 80px;
            resize: vertical;
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
        .mt-6  { margin-top: 6px; }
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php elseif ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php elseif ($msg === 'scrapped'): ?>
        <div class="msg msg-success">
            Battery was successfully scrapped and logged.
        </div>
    <?php endif; ?>

    <?php if ($view === 'menu'): ?>

        <h2>Main Menu</h2>
        <div class="menu-grid">
            <a class="btn" href="?view=inventory">Inventory</a>
            <a class="btn" href="?view=sell">Sell Battery</a>
            <a class="btn" href="?view=stocktruck">Stock Truck</a>
            <a class="btn" href="?view=transfer">Transfer Battery</a>
            <a class="btn" href="?view=scrap">Scrap Battery</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong> (<?= htmlspecialchars($empAAA) ?>).<br>
                Use the buttons above to manage batteries.
            </p>

            <?php if ($soldTodayCount > 0): ?>
                <p class="text-center" style="font-size:13px; color:#166534; margin-top:6px;">
                    You have sold <strong><?= $soldTodayCount ?></strong>
                    battery<?= ($soldTodayCount === 1 ? '' : 'ies') ?> so far today.
                </p>
            <?php endif; ?>

            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary" style="max-width:200px; display:inline-block;">
                    Logout
                </a>
            </div>
        </div>

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

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="sell_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('sell_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not previously <strong>SOLD</strong> or <strong>SCRAPPED</strong> are eligible.
            </p>
        </div>

        <!-- Step 2: Confirm Sale -->
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

        <?php if (!empty($transferSuccess)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($transferSuccess) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: BatteryID + Destination -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="transfer_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('transfer_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <label class="label-block mt-10">Transfer To</label>
                <select name="to_loc">
                    <option value="">Select Destination</option>
                    <?php foreach ($destRows as $d): ?>
                        <?php
                        $label = $d['ToLoc'] . ' (' . $d['Type'] . ')';
                        $val   = $d['ToLoc'];
                        ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                            <?= ($val === ($transferToLoc ?? '')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="preview_transfer" class="btn mt-10">
                    Preview Transfer
                </button>
            </form>

            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be transferred.
            </p>
        </div>

        <!-- Step 2: Preview + Confirm -->
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

    <?php elseif ($view === 'scrap'): ?>

        <h2>Scrap a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($scrapError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($scrapError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="scrap_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('scrap_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be scrapped.
            </p>
        </div>

        <!-- Step 2: Confirm Scrap + Reason -->
        <?php if ($scrapInfo): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Scrap</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($scrapInfo['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($scrapInfo['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($scrapInfo['DateCode']) ?></p>
                <p><strong>Current Location:</strong> <?= htmlspecialchars($scrapInfo['Location']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($scrapInfo['BatteryID']) ?>">

                    <label class="label-block">Reason for Scrap (required, max 255 chars)</label>
                    <textarea name="reason" maxlength="255"
                              placeholder="Describe why this battery is being scrapped."><?= isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : '' ?></textarea>

                    <button type="submit" name="confirm_scrap" class="btn mt-10">
                        Scrap This Battery
                    </button>
                </form>

                <p class="mt-6 small-note">
                    Reason text will be cleaned (for example, single quotes removed) before being stored in the audit log.
                </p>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'stocktruck'): ?>

        <h2>Stock Truck</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:14px;">
                Stock Truck functionality coming soon.
            </p>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Barcode Scanner Modal -->
<div id="scannerOverlay" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.8);
    z-index:9999;
    align-items:center;
    justify-content:center;
">
    <div style="background:#fff; padding:10px; border-radius:8px; max-width:400px; width:90%; text-align:center;">
        <h3 style="margin-top:0;">Scan Battery Barcode</h3>
        <video id="scannerVideo" style="width:100%; max-height:300px; background:#000;"></video>
        <p style="font-size:12px; color:#6b7280; margin-top:6px;">
            Align the barcode within the frame until it is detected.
        </p>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:8px;">
            <button type="button" onclick="switchCamera()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#2563eb;
                color:#fff;
            ">
                Switch Camera
            </button>
            <button type="button" onclick="closeScanner()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#4b5563;
                color:#fff;
            ">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ZXing and scanner script -->
<script src="https://unpkg.com/@zxing/library@latest"></script>
<script>
    let selectedInputId = null;
    let codeReader = null;
    let videoInputDevices = [];
    let currentDeviceIndex = 0;
    let currentStream = null;
    let audioCtx = null;

    function playBeep() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
            }
            const duration = 0.15; // seconds
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5-ish
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    }

    function stopCurrentStream() {
        const video = document.getElementById('scannerVideo');
        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }
        if (video) {
            video.srcObject = null;
        }
    }

    async function startDecodingWithCurrentDevice() {
        if (!videoInputDevices.length) return;

        const device = videoInputDevices[currentDeviceIndex];
        const deviceId = device.deviceId;

        stopCurrentStream();

        try {
            const video = document.getElementById('scannerVideo');

            // ZXing will call getUserMedia under the hood
            codeReader.decodeFromVideoDevice(deviceId, 'scannerVideo', (result, err) => {
                if (result) {
                    const input = document.getElementById(selectedInputId);
                    if (input) {
                        input.value = result.text;
                    }
                    playBeep();
                    closeScanner();
                }
                // err is usually "NotFoundException" while scanning – safe to ignore
            });

            // Save the stream from the video element once it starts
            setTimeout(() => {
                if (video && video.srcObject) {
                    currentStream = video.srcObject;
                }
            }, 500);

        } catch (e) {
            console.error('Error starting decode:', e);
            alert('Unable to start camera. Please check permissions.');
            closeScanner();
        }
    }

    function pickBackCameraIndex(devices) {
        // Prefer labels that look like back/rear/environment
        let idx = devices.findIndex(d =>
            /back|rear|environment/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Next, prefer anything that is NOT clearly front
        idx = devices.findIndex(d =>
            !/front|user/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Fallback to first
        return 0;
    }

    async function openScanner(inputId) {
        selectedInputId = inputId;
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'flex';

        if (!codeReader) {
            codeReader = new ZXing.BrowserMultiFormatReader();
        }

        try {
            // Get list of cameras
            videoInputDevices = await codeReader.listVideoInputDevices();
            if (!videoInputDevices.length) {
                alert('No camera found on this device.');
                closeScanner();
                return;
            }

            currentDeviceIndex = pickBackCameraIndex(videoInputDevices);
            await startDecodingWithCurrentDevice();
        } catch (e) {
            console.error(e);
            alert('Unable to access camera. Please check permissions.');
            closeScanner();
        }
    }

    function switchCamera() {
        if (!videoInputDevices.length || !codeReader) return;

        try {
            codeReader.reset();
        } catch (e) {
            console.warn(e);
        }
        stopCurrentStream();

        currentDeviceIndex = (currentDeviceIndex + 1) % videoInputDevices.length;
        startDecodingWithCurrentDevice();
    }

    function closeScanner() {
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'none';

        if (codeReader) {
            try {
                codeReader.reset();
            } catch (e) {
                console.warn(e);
            }
        }
        stopCurrentStream();
    }
</script>
</body>
</html>
You said:
After the transfer please clear the BatteryID prompt
also
I need to add a button on the main screen called History
When they open I want it defaulting to a select from the AuditLog where EmployeeID = AAA Number from login
Show BatteryID, Battery, Type, ToLoc as To, FromLoc as From, and LastUpdate as Time/Date showing the most recent event and show last 25 records. 
ChatGPT said:

Thought for 49s
Got it—keep them on the transfer screen, clear the BatteryID after a successful transfer, and add a History screen filtered to the logged-in AAA with the last 25 events. Here’s the full updated code with those changes wired in:

<?php
// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
    // Clear session data
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        // Session expired
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    } else {
        // Still active, refresh timer
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE (NO OUTPUT BEFORE THIS POINT) =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Please enter both AAA and Password.";
        } else {
            try {
                $dsnLogin = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
                $pdoLogin = new PDO($dsnLogin, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "
                    SELECT AAA, FirstName, LastName
                    FROM Employee
                    WHERE AAA = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA'];
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', '', 'MOBILE', :lastUpdate)
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']     = true;
                    $_SESSION['empAAA']        = $empId;
                    $_SESSION['empFirst']      = $emp['FirstName'];
                    $_SESSION['empLast']       = $emp['LastName'];
                    $_SESSION['empName']       = $empName;
                    $_SESSION['last_activity'] = time(); // start timeout timer

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }

            } catch (Exception $e) {
                $error = "Login error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Browns Towing Battery Program Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2 style="text-align:center;">Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p style="color:orange;">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>AAA:</label><br>
            <input type="text" name="aaa"
                   style="width:100%; padding:8px; margin:8px 0;"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

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

// ===== WE HAVE A LOGGED IN USER =====
$empAAA  = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName = $_SESSION['empName'] ?? 'Tuna Marie';

// ===== CONNECT TO DB =====
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE =====
// views: menu | inventory | sell | transfer | scrap | stocktruck | history
$view = $_GET['view'] ?? 'menu';
$msg  = $_GET['msg']  ?? '';

// Predeclare vars
$invRows        = [];
$allLocations   = [];
$allBatteries   = [];

$sellError      = "";
$sellInfo       = null;

$transferError      = "";
$transferSuccess    = "";
$transferPreview    = null;
$transferToLoc      = "";
$transferBatteryId  = "";
$destRows           = [];

$scrapError      = "";
$scrapInfo       = null;

$historyRows     = [];

$soldTodayCount  = 0;

// ===== INVENTORY SECTION =====
if ($view === 'inventory') {
    $selectedLocation = isset($_GET['loc']) ? trim($_GET['loc']) : '';
    $selectedBattery  = isset($_GET['bat']) ? trim($_GET['bat']) : '';

    // Dropdown data (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sqlAll = "
        SELECT 
            Inventory.Battery AS Battery,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
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

    // Aggregated query (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sql = "
        SELECT 
            Inventory.Battery AS Battery,
            COUNT(*) AS Quantity,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
    ";
    $params = [];

    if ($selectedLocation !== '') {
        $sql .= " AND Inventory.Location = :loc";
        $params[':loc'] = $selectedLocation;
    }
    if ($selectedBattery !== '') {
        $sql .= " AND Inventory.Battery = :bat";
        $params[':bat'] = $selectedBattery;
    }

    $sql .= "
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== SELL BATTERY SECTION =====
if ($view === 'sell') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $sellError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm sale
        elseif (isset($_POST['confirm_sell'])) {
            $bid = trim($_POST['battery_id'] ?? '');

            if ($bid === '') {
                $sellError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $fromLoc = $row['Location'];
                        $now     = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory 
                            SET Location = 'SOLD' 
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                            VALUES
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':batteryId'  => $row['BatteryID'],
                            ':battery'    => $row['Battery'],
                            ':dateCode'   => $row['DateCode'],
                            ':lastUpdate' => $now,
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

// ===== TRANSFER BATTERY SECTION =====
if ($view === 'transfer') {
    // Build combined destination list: shops + trucks
    $stmtDest = $pdo->query("
        SELECT Location AS ToLoc, 'SHOP' AS Type
        FROM Location
        UNION ALL
        SELECT Truck AS ToLoc, 'TRUCK' AS Type
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
    ");
    $rowsDest = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($rowsDest as $r) {
        $loc  = trim($r['ToLoc'] ?? '');
        $type = $r['Type'] ?? 'TRUCK';
        if ($loc === '') continue;
        if (!isset($seen[$loc])) {
            $seen[$loc] = $type;
        }
    }

    foreach ($seen as $loc => $type) {
        $destRows[] = [
            'ToLoc' => $loc,
            'Type'  => $type
        ];
    }

    // Sort: shops first, then trucks; each group descending by name
    usort($destRows, function($a, $b) {
        if ($a['Type'] !== $b['Type']) {
            return ($a['Type'] === 'SHOP') ? -1 : 1;
        }
        return strcasecmp($b['ToLoc'], $a['ToLoc']);
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Preview transfer
        if (isset($_POST['preview_transfer'])) {
            $bid        = trim($_POST['battery_id'] ?? '');
            $transferTo = trim($_POST['to_loc'] ?? '');
            $transferToLoc     = $transferTo;
            $transferBatteryId = $bid;

            if ($bid === '') {
                $transferError = "Please enter a BatteryID.";
            } elseif ($transferTo === '') {
                $transferError = "Please select a destination.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED.";
                } else {
                    $fromLoc = $row['Location'];

                    if ($fromLoc === $transferTo) {
                        $transferError = "BatteryID already at location.";
                    } else {
                        $transferPreview = [
                            'BatteryID' => $row['BatteryID'],
                            'Battery'   => $row['Battery'],
                            'DateCode'  => $row['DateCode'],
                            'FromLoc'   => $fromLoc,
                            'ToLoc'     => $transferTo,
                        ];
                    }
                }
            }
        }

        // Step 2: Confirm transfer
        elseif (isset($_POST['confirm_transfer'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $fromLoc   = trim($_POST['from_loc'] ?? '');
            $toLoc     = trim($_POST['to_loc'] ?? '');
            $battery   = trim($_POST['battery'] ?? '');
            $dateCode  = trim($_POST['date_code'] ?? '');

            // keep UI selections
            $transferToLoc     = $toLoc;
            $transferBatteryId = $bid;

            if ($bid === '' || $fromLoc === '' || $toLoc === '') {
                $transferError = "Missing transfer data. Please try again.";
            } elseif ($fromLoc === $toLoc) {
                $transferError = "BatteryID already at location.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $check = $pdo->prepare("
                        SELECT Inventory.Location
                        FROM Inventory
                        WHERE BatteryID = :bid
                          AND Location NOT IN ('SOLD','SCRAPPED')
                          AND StockType = 'BATTERY'
                    ");
                    $check->execute([':bid' => $bid]);
                    $current = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$current || $current['Location'] !== $fromLoc) {
                        $pdo->rollBack();
                        $transferError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                    } else {
                        $now = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :toLoc
                            WHERE BatteryID = :bid
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([
                            ':toLoc' => $toLoc,
                            ':bid'   => $bid
                        ]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':toLoc'      => $toLoc,
                            ':batteryId'  => $bid,
                            ':battery'    => $battery,
                            ':dateCode'   => $dateCode,
                            ':lastUpdate' => $now,
                        ]);

                        $pdo->commit();

                        // Stay on Transfer page and show success
                        $transferSuccess   = "Battery was successfully transferred and logged.";
                        $transferPreview   = null;
                        $transferBatteryId = ""; // clear BatteryID prompt after successful transfer
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $transferError = "Error transferring battery: " . $e->getMessage();
                }
            }
        }
    }
}

// ===== SCRAP BATTERY SECTION =====
if ($view === 'scrap') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup battery
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $scrapError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $scrapInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$scrapInfo) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm scrap
        elseif (isset($_POST['confirm_scrap'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $reasonRaw = $_POST['reason'] ?? '';

            // We’ll requery battery info for safety
            if ($bid === '') {
                $scrapError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    // Validate reason
                    $reasonTrim  = trim($reasonRaw);
                    if ($reasonTrim === '') {
                        $scrapError = "Reason is required to scrap a battery.";
                        $scrapInfo  = $row; // keep display
                    } else {
                        // Clean reason: remove single quotes & limit to 255 chars
                        $reasonClean = str_replace("'", "", $reasonTrim);
                        $reasonClean = mb_substr($reasonClean, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $fromLoc = $row['Location'];
                            $now     = date('Y-m-d H:i:s');

                            // Update Inventory to SCRAPPED
                            $update = $pdo->prepare("
                                UPDATE Inventory 
                                SET Location = 'SCRAPPED'
                                WHERE BatteryID = :bid
                                  AND Location NOT IN ('SOLD','SCRAPPED')
                                  AND StockType = 'BATTERY'
                            ");
                            $update->execute([':bid' => $bid]);

                            // Insert AuditLog (ToLoc = SCRAPPED, Reason = user text)
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                                VALUES
                                    (:empId, :empName, :fromLoc, 'SCRAPPED', :batteryId, 'Scrap', '', :battery, :dateCode, :reason, :fromLoc, 'MOBILE', :lastUpdate)
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':batteryId'  => $row['BatteryID'],
                                ':battery'    => $row['Battery'],
                                ':dateCode'   => $row['DateCode'],
                                ':reason'     => $reasonClean,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=scrapped");
                            exit;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $scrapError = "Error scrapping battery: " . $e->getMessage();
                            $scrapInfo  = $row;
                        }
                    }
                }
            }
        }
    }
}

// ===== HISTORY SECTION =====
if ($view === 'history') {
    try {
        $stmtHist = $pdo->prepare("
            SELECT BatteryID, Battery, Type, ToLoc, FromLoc, LastUpdate
            FROM AuditLog
            WHERE EmployeeID = :empId
            ORDER BY LastUpdate DESC
            LIMIT 25
        ");
        $stmtHist->execute([':empId' => $empAAA]);
        $historyRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $historyRows = [];
    }
}

// ===== SOLD TODAY COUNT (MENU ONLY, BASED ON EST/EDT) =====
if ($view === 'menu') {
    try {
        // Local "today" in EST/EDT
        $startToday = date('Y-m-d 00:00:00');
        $endToday   = date('Y-m-d 23:59:59');

        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND LastUpdate >= :startToday
              AND LastUpdate <= :endToday
        ");
        $stmtSold->execute([
            ':empId'      => $empAAA,
            ':startToday' => $startToday,
            ':endToday'   => $endToday,
        ]);
        $soldTodayCount = (int)$stmtSold->fetchColumn();
    } catch (Exception $e) {
        $soldTodayCount = 0; // fail quietly
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
                grid-template-columns: repeat(4, 1fr);
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
        select, input[type="text"], textarea {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        textarea {
            min-height: 80px;
            resize: vertical;
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
        .mt-6  { margin-top: 6px; }
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php elseif ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php elseif ($msg === 'scrapped'): ?>
        <div class="msg msg-success">
            Battery was successfully scrapped and logged.
        </div>
    <?php endif; ?>

    <?php if ($view === 'menu'): ?>

        <h2>Main Menu</h2>
        <div class="menu-grid">
            <a class="btn" href="?view=inventory">Inventory</a>
            <a class="btn" href="?view=sell">Sell Battery</a>
            <a class="btn" href="?view=stocktruck">Stock Truck</a>
            <a class="btn" href="?view=transfer">Transfer Battery</a>
            <a class="btn" href="?view=scrap">Scrap Battery</a>
            <a class="btn" href="?view=history">History</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong> (<?= htmlspecialchars($empAAA) ?>).<br>
                Use the buttons above to manage batteries.
            </p>

            <?php if ($soldTodayCount > 0): ?>
                <p class="text-center" style="font-size:13px; color:#166534; margin-top:6px;">
                    You have sold <strong><?= $soldTodayCount ?></strong>
                    battery<?= ($soldTodayCount === 1 ? '' : 'ies') ?> so far today.
                </p>
            <?php endif; ?>

            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary" style="max-width:200px; display:inline-block;">
                    Logout
                </a>
            </div>
        </div>

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

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="sell_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('sell_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not previously <strong>SOLD</strong> or <strong>SCRAPPED</strong> are eligible.
            </p>
        </div>

        <!-- Step 2: Confirm Sale -->
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

        <?php if (!empty($transferSuccess)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($transferSuccess) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: BatteryID + Destination -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="transfer_battery_id"
                           style="flex:1;"
                           value="<?= htmlspecialchars($transferBatteryId ?? '') ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('transfer_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <label class="label-block mt-10">Transfer To</label>
                <select name="to_loc">
                    <option value="">Select Destination</option>
                    <?php foreach ($destRows as $d): ?>
                        <?php
                        $label = $d['ToLoc'] . ' (' . $d['Type'] . ')';
                        $val   = $d['ToLoc'];
                        ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                            <?= ($val === ($transferToLoc ?? '')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="preview_transfer" class="btn mt-10">
                    Preview Transfer
                </button>
            </form>

            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be transferred.
            </p>
        </div>

        <!-- Step 2: Preview + Confirm -->
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

    <?php elseif ($view === 'scrap'): ?>

        <h2>Scrap a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($scrapError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($scrapError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="scrap_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('scrap_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be scrapped.
            </p>
        </div>

        <!-- Step 2: Confirm Scrap + Reason -->
        <?php if ($scrapInfo): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Scrap</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($scrapInfo['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($scrapInfo['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($scrapInfo['DateCode']) ?></p>
                <p><strong>Current Location:</strong> <?= htmlspecialchars($scrapInfo['Location']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($scrapInfo['BatteryID']) ?>">

                    <label class="label-block">Reason for Scrap (required, max 255 chars)</label>
                    <textarea name="reason" maxlength="255"
                              placeholder="Describe why this battery is being scrapped."><?= isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : '' ?></textarea>

                    <button type="submit" name="confirm_scrap" class="btn mt-10">
                        Scrap This Battery
                    </button>
                </form>

                <p class="mt-6 small-note">
                    Reason text will be cleaned (for example, single quotes removed) before being stored in the audit log.
                </p>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'history'): ?>

        <h2>History</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="small-note">
                Showing the most recent 25 events for <strong><?= htmlspecialchars($empAAA) ?></strong>.
            </p>
            <div class="table-container">
                <table>
                    <tr>
                        <th>BatteryID</th>
                        <th>Battery</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Time/Date</th>
                    </tr>
                    <?php if (empty($historyRows)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No history found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historyRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['BatteryID'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Battery']   ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Type']      ?? '') ?></td>
                                <td><?= htmlspecialchars($row['FromLoc']   ?? '') ?></td>
                                <td><?= htmlspecialchars($row['ToLoc']     ?? '') ?></td>
                                <td><?= htmlspecialchars($row['LastUpdate'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    <?php elseif ($view === 'stocktruck'): ?>

        <h2>Stock Truck</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:14px;">
                Stock Truck functionality coming soon.
            </p>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Barcode Scanner Modal -->
<div id="scannerOverlay" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.8);
    z-index:9999;
    align-items:center;
    justify-content:center;
">
    <div style="background:#fff; padding:10px; border-radius:8px; max-width:400px; width:90%; text-align:center;">
        <h3 style="margin-top:0;">Scan Battery Barcode</h3>
        <video id="scannerVideo" style="width:100%; max-height:300px; background:#000;"></video>
        <p style="font-size:12px; color:#6b7280; margin-top:6px;">
            Align the barcode within the frame until it is detected.
        </p>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:8px;">
            <button type="button" onclick="switchCamera()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#2563eb;
                color:#fff;
            ">
                Switch Camera
            </button>
            <button type="button" onclick="closeScanner()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#4b5563;
                color:#fff;
            ">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ZXing and scanner script -->
<script src="https://unpkg.com/@zxing/library@latest"></script>
<script>
    let selectedInputId = null;
    let codeReader = null;
    let videoInputDevices = [];
    let currentDeviceIndex = 0;
    let currentStream = null;
    let audioCtx = null;

    function playBeep() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
            }
            const duration = 0.15; // seconds
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5-ish
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    }

    function stopCurrentStream() {
        const video = document.getElementById('scannerVideo');
        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }
        if (video) {
            video.srcObject = null;
        }
    }

    async function startDecodingWithCurrentDevice() {
        if (!videoInputDevices.length) return;

        const device = videoInputDevices[currentDeviceIndex];
        const deviceId = device.deviceId;

        stopCurrentStream();

        try {
            const video = document.getElementById('scannerVideo');

            // ZXing will call getUserMedia under the hood
            codeReader.decodeFromVideoDevice(deviceId, 'scannerVideo', (result, err) => {
                if (result) {
                    const input = document.getElementById(selectedInputId);
                    if (input) {
                        input.value = result.text;
                    }
                    playBeep();
                    closeScanner();
                }
                // err is usually "NotFoundException" while scanning – safe to ignore
            });

            // Save the stream from the video element once it starts
            setTimeout(() => {
                if (video && video.srcObject) {
                    currentStream = video.srcObject;
                }
            }, 500);

        } catch (e) {
            console.error('Error starting decode:', e);
            alert('Unable to start camera. Please check permissions.');
            closeScanner();
        }
    }

    function pickBackCameraIndex(devices) {
        // Prefer labels that look like back/rear/environment
        let idx = devices.findIndex(d =>
            /back|rear|environment/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Next, prefer anything that is NOT clearly front
        idx = devices.findIndex(d =>
            !/front|user/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Fallback to first
        return 0;
    }

    async function openScanner(inputId) {
        selectedInputId = inputId;
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'flex';

        if (!codeReader) {
            codeReader = new ZXing.BrowserMultiFormatReader();
        }

        try {
            // Get list of cameras
            videoInputDevices = await codeReader.listVideoInputDevices();
            if (!videoInputDevices.length) {
                alert('No camera found on this device.');
                closeScanner();
                return;
            }

            currentDeviceIndex = pickBackCameraIndex(videoInputDevices);
            await startDecodingWithCurrentDevice();
        } catch (e) {
            console.error(e);
            alert('Unable to access camera. Please check permissions.');
            closeScanner();
        }
    }

    function switchCamera() {
        if (!videoInputDevices.length || !codeReader) return;

        try {
            codeReader.reset();
        } catch (e) {
            console.warn(e);
        }
        stopCurrentStream();

        currentDeviceIndex = (currentDeviceIndex + 1) % videoInputDevices.length;
        startDecodingWithCurrentDevice();
    }

    function closeScanner() {
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'none';

        if (codeReader) {
            try {
                codeReader.reset();
            } catch (e) {
                console.warn(e);
            }
        }
        stopCurrentStream();
    }
</script>
</body>
</html>
If you want to tweak the History view later (date range filter, show all employees for admins, etc.), we can bolt that on top of this pretty easily.

You said:
On the History can you exclude type Log On and Receive. Also filter on StockType Battery
For the message on the top, can you replace the AAA number with their name. So it says "Show the most recent 25 events for NAME
ChatGPT said:

Thought for 41s
You got it 👍

Here are the exact changes to make.

1️⃣ Update the HISTORY query
Replace your current HISTORY SECTION PHP block:

// ===== HISTORY SECTION =====
if ($view === 'history') {
    try {
        $stmtHist = $pdo->prepare("
            SELECT BatteryID, Battery, Type, ToLoc, FromLoc, LastUpdate
            FROM AuditLog
            WHERE EmployeeID = :empId
            ORDER BY LastUpdate DESC
            LIMIT 25
        ");
        $stmtHist->execute([':empId' => $empAAA]);
        $historyRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $historyRows = [];
    }
}
with this version that:

Excludes Type IN ('Log On','Receive')

Filters to StockType = 'BATTERY' via Inventory

Keeps the last 25 most recent events

// ===== HISTORY SECTION =====
if ($view === 'history') {
    try {
        $stmtHist = $pdo->prepare("
            SELECT 
                a.BatteryID,
                a.Battery,
                a.Type,
                a.ToLoc,
                a.FromLoc,
                a.LastUpdate
            FROM AuditLog a
            JOIN Inventory i
              ON i.BatteryID = a.BatteryID
             AND i.StockType = 'BATTERY'
            WHERE a.EmployeeID = :empId
              AND a.Type NOT IN ('Log On', 'Receive')
            ORDER BY a.LastUpdate DESC
            LIMIT 25
        ");
        $stmtHist->execute([':empId' => $empAAA]);
        $historyRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $historyRows = [];
    }
}
2️⃣ Update the History header message text
In the History view HTML, find this line:

<p class="small-note">
    Showing the most recent 25 events for <strong><?= htmlspecialchars($empAAA) ?></strong>.
</p>
Change it to use their name instead of AAA:

<p class="small-note">
    Showing the most recent 25 events for <strong><?= htmlspecialchars($empName) ?></strong>.
</p>
Those two tweaks will:

Hide Log On and Receive from History

Only show events tied to battery stock

Display the friendly name instead of the AAA ID in the header ✅

You said:
can you give me the full build
ChatGPT said:

Thought for 7s
Here’s the full updated build with your latest changes:

Transfer screen:

Stays on Transfer after success

Clears the BatteryID field after a successful transfer

History screen:

New History button on main menu

Shows last 25 events

Filtered by EmployeeID = AAA from login

Excludes Type = 'Log On' and Type = 'Receive'

Only shows rows where Inventory.StockType = 'BATTERY'

Top message uses the employee’s name, not AAA

<?php
// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
    // Clear session data
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        // Session expired
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    } else {
        // Still active, refresh timer
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE (NO OUTPUT BEFORE THIS POINT) =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Please enter both AAA and Password.";
        } else {
            try {
                $dsnLogin = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
                $pdoLogin = new PDO($dsnLogin, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "
                    SELECT AAA, FirstName, LastName
                    FROM Employee
                    WHERE AAA = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA'];
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', '', 'MOBILE', :lastUpdate)
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']     = true;
                    $_SESSION['empAAA']        = $empId;
                    $_SESSION['empFirst']      = $emp['FirstName'];
                    $_SESSION['empLast']       = $emp['LastName'];
                    $_SESSION['empName']       = $empName;
                    $_SESSION['last_activity'] = time(); // start timeout timer

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }

            } catch (Exception $e) {
                $error = "Login error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Browns Towing Battery Program Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2 style="text-align:center;">Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p style="color:orange;">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>AAA:</label><br>
            <input type="text" name="aaa"
                   style="width:100%; padding:8px; margin:8px 0;"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

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

// ===== WE HAVE A LOGGED IN USER =====
$empAAA  = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName = $_SESSION['empName'] ?? 'Tuna Marie';

// ===== CONNECT TO DB =====
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE =====
// views: menu | inventory | sell | transfer | scrap | stocktruck | history
$view = $_GET['view'] ?? 'menu';
$msg  = $_GET['msg']  ?? '';

// Predeclare vars
$invRows        = [];
$allLocations   = [];
$allBatteries   = [];

$sellError      = "";
$sellInfo       = null;

$transferError      = "";
$transferSuccess    = "";
$transferPreview    = null;
$transferToLoc      = "";
$transferBatteryId  = "";
$destRows           = [];

$scrapError      = "";
$scrapInfo       = null;

$historyRows     = [];

$soldTodayCount  = 0;

// ===== INVENTORY SECTION =====
if ($view === 'inventory') {
    $selectedLocation = isset($_GET['loc']) ? trim($_GET['loc']) : '';
    $selectedBattery  = isset($_GET['bat']) ? trim($_GET['bat']) : '';

    // Dropdown data (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sqlAll = "
        SELECT 
            Inventory.Battery AS Battery,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
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

    // Aggregated query (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sql = "
        SELECT 
            Inventory.Battery AS Battery,
            COUNT(*) AS Quantity,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
    ";
    $params = [];

    if ($selectedLocation !== '') {
        $sql .= " AND Inventory.Location = :loc";
        $params[':loc'] = $selectedLocation;
    }
    if ($selectedBattery !== '') {
        $sql .= " AND Inventory.Battery = :bat";
        $params[':bat'] = $selectedBattery;
    }

    $sql .= "
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== SELL BATTERY SECTION =====
if ($view === 'sell') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $sellError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm sale
        elseif (isset($_POST['confirm_sell'])) {
            $bid = trim($_POST['battery_id'] ?? '');

            if ($bid === '') {
                $sellError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $fromLoc = $row['Location'];
                        $now     = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory 
                            SET Location = 'SOLD' 
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                            VALUES
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':batteryId'  => $row['BatteryID'],
                            ':battery'    => $row['Battery'],
                            ':dateCode'   => $row['DateCode'],
                            ':lastUpdate' => $now,
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

// ===== TRANSFER BATTERY SECTION =====
if ($view === 'transfer') {
    // Build combined destination list: shops + trucks
    $stmtDest = $pdo->query("
        SELECT Location AS ToLoc, 'SHOP' AS Type
        FROM Location
        UNION ALL
        SELECT Truck AS ToLoc, 'TRUCK' AS Type
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
    ");
    $rowsDest = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($rowsDest as $r) {
        $loc  = trim($r['ToLoc'] ?? '');
        $type = $r['Type'] ?? 'TRUCK';
        if ($loc === '') continue;
        if (!isset($seen[$loc])) {
            $seen[$loc] = $type;
        }
    }

    foreach ($seen as $loc => $type) {
        $destRows[] = [
            'ToLoc' => $loc,
            'Type'  => $type
        ];
    }

    // Sort: shops first, then trucks; each group descending by name
    usort($destRows, function($a, $b) {
        if ($a['Type'] !== $b['Type']) {
            return ($a['Type'] === 'SHOP') ? -1 : 1;
        }
        return strcasecmp($b['ToLoc'], $a['ToLoc']);
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Preview transfer
        if (isset($_POST['preview_transfer'])) {
            $bid        = trim($_POST['battery_id'] ?? '');
            $transferTo = trim($_POST['to_loc'] ?? '');
            $transferToLoc     = $transferTo;
            $transferBatteryId = $bid;

            if ($bid === '') {
                $transferError = "Please enter a BatteryID.";
            } elseif ($transferTo === '') {
                $transferError = "Please select a destination.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED.";
                } else {
                    $fromLoc = $row['Location'];

                    if ($fromLoc === $transferTo) {
                        $transferError = "BatteryID already at location.";
                    } else {
                        $transferPreview = [
                            'BatteryID' => $row['BatteryID'],
                            'Battery'   => $row['Battery'],
                            'DateCode'  => $row['DateCode'],
                            'FromLoc'   => $fromLoc,
                            'ToLoc'     => $transferTo,
                        ];
                    }
                }
            }
        }

        // Step 2: Confirm transfer
        elseif (isset($_POST['confirm_transfer'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $fromLoc   = trim($_POST['from_loc'] ?? '');
            $toLoc     = trim($_POST['to_loc'] ?? '');
            $battery   = trim($_POST['battery'] ?? '');
            $dateCode  = trim($_POST['date_code'] ?? '');

            // keep UI selections
            $transferToLoc     = $toLoc;
            $transferBatteryId = $bid;

            if ($bid === '' || $fromLoc === '' || $toLoc === '') {
                $transferError = "Missing transfer data. Please try again.";
            } elseif ($fromLoc === $toLoc) {
                $transferError = "BatteryID already at location.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $check = $pdo->prepare("
                        SELECT Inventory.Location
                        FROM Inventory
                        WHERE BatteryID = :bid
                          AND Location NOT IN ('SOLD','SCRAPPED')
                          AND StockType = 'BATTERY'
                    ");
                    $check->execute([':bid' => $bid]);
                    $current = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$current || $current['Location'] !== $fromLoc) {
                        $pdo->rollBack();
                        $transferError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                    } else {
                        $now = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :toLoc
                            WHERE BatteryID = :bid
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([
                            ':toLoc' => $toLoc,
                            ':bid'   => $bid
                        ]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':toLoc'      => $toLoc,
                            ':batteryId'  => $bid,
                            ':battery'    => $battery,
                            ':dateCode'   => $dateCode,
                            ':lastUpdate' => $now,
                        ]);

                        $pdo->commit();

                        // Stay on Transfer page and show success
                        $transferSuccess   = "Battery was successfully transferred and logged.";
                        $transferPreview   = null;
                        $transferBatteryId = ""; // clear BatteryID prompt after successful transfer
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $transferError = "Error transferring battery: " . $e->getMessage();
                }
            }
        }
    }
}

// ===== SCRAP BATTERY SECTION =====
if ($view === 'scrap') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup battery
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $scrapError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $scrapInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$scrapInfo) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm scrap
        elseif (isset($_POST['confirm_scrap'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $reasonRaw = $_POST['reason'] ?? '';

            // We’ll requery battery info for safety
            if ($bid === '') {
                $scrapError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    // Validate reason
                    $reasonTrim  = trim($reasonRaw);
                    if ($reasonTrim === '') {
                        $scrapError = "Reason is required to scrap a battery.";
                        $scrapInfo  = $row; // keep display
                    } else {
                        // Clean reason: remove single quotes & limit to 255 chars
                        $reasonClean = str_replace("'", "", $reasonTrim);
                        $reasonClean = mb_substr($reasonClean, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $fromLoc = $row['Location'];
                            $now     = date('Y-m-d H:i:s');

                            // Update Inventory to SCRAPPED
                            $update = $pdo->prepare("
                                UPDATE Inventory 
                                SET Location = 'SCRAPPED'
                                WHERE BatteryID = :bid
                                  AND Location NOT IN ('SOLD','SCRAPPED')
                                  AND StockType = 'BATTERY'
                            ");
                            $update->execute([':bid' => $bid]);

                            // Insert AuditLog (ToLoc = SCRAPPED, Reason = user text)
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate)
                                VALUES
                                    (:empId, :empName, :fromLoc, 'SCRAPPED', :batteryId, 'Scrap', '', :battery, :dateCode, :reason, :fromLoc, 'MOBILE', :lastUpdate)
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':batteryId'  => $row['BatteryID'],
                                ':battery'    => $row['Battery'],
                                ':dateCode'   => $row['DateCode'],
                                ':reason'     => $reasonClean,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=scrapped");
                            exit;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $scrapError = "Error scrapping battery: " . $e->getMessage();
                            $scrapInfo  = $row;
                        }
                    }
                }
            }
        }
    }
}

// ===== HISTORY SECTION =====
if ($view === 'history') {
    try {
        $stmtHist = $pdo->prepare("
            SELECT 
                a.BatteryID,
                a.Battery,
                a.Type,
                a.ToLoc,
                a.FromLoc,
                a.LastUpdate
            FROM AuditLog a
            JOIN Inventory i
              ON i.BatteryID = a.BatteryID
             AND i.StockType = 'BATTERY'
            WHERE a.EmployeeID = :empId
              AND a.Type NOT IN ('Log On', 'Receive')
            ORDER BY a.LastUpdate DESC
            LIMIT 25
        ");
        $stmtHist->execute([':empId' => $empAAA]);
        $historyRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $historyRows = [];
    }
}

// ===== SOLD TODAY COUNT (MENU ONLY, BASED ON EST/EDT) =====
if ($view === 'menu') {
    try {
        // Local "today" in EST/EDT
        $startToday = date('Y-m-d 00:00:00');
        $endToday   = date('Y-m-d 23:59:59');

        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND LastUpdate >= :startToday
              AND LastUpdate <= :endToday
        ");
        $stmtSold->execute([
            ':empId'      => $empAAA,
            ':startToday' => $startToday,
            ':endToday'   => $endToday,
        ]);
        $soldTodayCount = (int)$stmtSold->fetchColumn();
    } catch (Exception $e) {
        $soldTodayCount = 0; // fail quietly
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
                grid-template-columns: repeat(4, 1fr);
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
        select, input[type="text"], textarea {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        textarea {
            min-height: 80px;
            resize: vertical;
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
        .mt-6  { margin-top: 6px; }
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php elseif ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php elseif ($msg === 'scrapped'): ?>
        <div class="msg msg-success">
            Battery was successfully scrapped and logged.
        </div>
    <?php endif; ?>

    <?php if ($view === 'menu'): ?>

        <h2>Main Menu</h2>
        <div class="menu-grid">
            <a class="btn" href="?view=inventory">Inventory</a>
            <a class="btn" href="?view=sell">Sell Battery</a>
            <a class="btn" href="?view=stocktruck">Stock Truck</a>
            <a class="btn" href="?view=transfer">Transfer Battery</a>
            <a class="btn" href="?view=scrap">Scrap Battery</a>
            <a class="btn" href="?view=history">History</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong> (<?= htmlspecialchars($empAAA) ?>).<br>
                Use the buttons above to manage batteries.
            </p>

            <?php if ($soldTodayCount > 0): ?>
                <p class="text-center" style="font-size:13px; color:#166534; margin-top:6px;">
                    You have sold <strong><?= $soldTodayCount ?></strong>
                    battery<?= ($soldTodayCount === 1 ? '' : 'ies') ?> so far today.
                </p>
            <?php endif; ?>

            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary" style="max-width:200px; display:inline-block;">
                    Logout
                </a>
            </div>
        </div>

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

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="sell_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('sell_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not previously <strong>SOLD</strong> or <strong>SCRAPPED</strong> are eligible.
            </p>
        </div>

        <!-- Step 2: Confirm Sale -->
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

        <?php if (!empty($transferSuccess)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($transferSuccess) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: BatteryID + Destination -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="transfer_battery_id"
                           style="flex:1;"
                           value="<?= htmlspecialchars($transferBatteryId ?? '') ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('transfer_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <label class="label-block mt-10">Transfer To</label>
                <select name="to_loc">
                    <option value="">Select Destination</option>
                    <?php foreach ($destRows as $d): ?>
                        <?php
                        $label = $d['ToLoc'] . ' (' . $d['Type'] . ')';
                        $val   = $d['ToLoc'];
                        ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                            <?= ($val === ($transferToLoc ?? '')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="preview_transfer" class="btn mt-10">
                    Preview Transfer
                </button>
            </form>

            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be transferred.
            </p>
        </div>

        <!-- Step 2: Preview + Confirm -->
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

    <?php elseif ($view === 'scrap'): ?>

        <h2>Scrap a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($scrapError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($scrapError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="scrap_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('scrap_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be scrapped.
            </p>
        </div>

        <!-- Step 2: Confirm Scrap + Reason -->
        <?php if ($scrapInfo): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Scrap</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($scrapInfo['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($scrapInfo['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($scrapInfo['DateCode']) ?></p>
                <p><strong>Current Location:</strong> <?= htmlspecialchars($scrapInfo['Location']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($scrapInfo['BatteryID']) ?>">

                    <label class="label-block">Reason for Scrap (required, max 255 chars)</label>
                    <textarea name="reason" maxlength="255"
                              placeholder="Describe why this battery is being scrapped."><?= isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : '' ?></textarea>

                    <button type="submit" name="confirm_scrap" class="btn mt-10">
                        Scrap This Battery
                    </button>
                </form>

                <p class="mt-6 small-note">
                    Reason text will be cleaned (for example, single quotes removed) before being stored in the audit log.
                </p>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'history'): ?>

        <h2>History</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="small-note">
                Showing the most recent 25 events for <strong><?= htmlspecialchars($empName) ?></strong>.
            </p>
            <div class="table-container">
                <table>
                    <tr>
                        <th>BatteryID</th>
                        <th>Battery</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Time/Date</th>
                    </tr>
                    <?php if (empty($historyRows)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No history found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historyRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['BatteryID']  ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Battery']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Type']       ?? '') ?></td>
                                <td><?= htmlspecialchars($row['FromLoc']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['ToLoc']      ?? '') ?></td>
                                <td><?= htmlspecialchars($row['LastUpdate'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    <?php elseif ($view === 'stocktruck'): ?>

        <h2>Stock Truck</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:14px;">
                Stock Truck functionality coming soon.
            </p>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Barcode Scanner Modal -->
<div id="scannerOverlay" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.8);
    z-index:9999;
    align-items:center;
    justify-content:center;
">
    <div style="background:#fff; padding:10px; border-radius:8px; max-width:400px; width:90%; text-align:center;">
        <h3 style="margin-top:0;">Scan Battery Barcode</h3>
        <video id="scannerVideo" style="width:100%; max-height:300px; background:#000;"></video>
        <p style="font-size:12px; color:#6b7280; margin-top:6px;">
            Align the barcode within the frame until it is detected.
        </p>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:8px;">
            <button type="button" onclick="switchCamera()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#2563eb;
                color:#fff;
            ">
                Switch Camera
            </button>
            <button type="button" onclick="closeScanner()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#4b5563;
                color:#fff;
            ">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ZXing and scanner script -->
<script src="https://unpkg.com/@zxing/library@latest"></script>
<script>
    let selectedInputId = null;
    let codeReader = null;
    let videoInputDevices = [];
    let currentDeviceIndex = 0;
    let currentStream = null;
    let audioCtx = null;

    function playBeep() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
            }
            const duration = 0.15; // seconds
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5-ish
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    }

    function stopCurrentStream() {
        const video = document.getElementById('scannerVideo');
        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }
        if (video) {
            video.srcObject = null;
        }
    }

    async function startDecodingWithCurrentDevice() {
        if (!videoInputDevices.length) return;

        const device = videoInputDevices[currentDeviceIndex];
        const deviceId = device.deviceId;

        stopCurrentStream();

        try {
            const video = document.getElementById('scannerVideo');

            // ZXing will call getUserMedia under the hood
            codeReader.decodeFromVideoDevice(deviceId, 'scannerVideo', (result, err) => {
                if (result) {
                    const input = document.getElementById(selectedInputId);
                    if (input) {
                        input.value = result.text;
                    }
                    playBeep();
                    closeScanner();
                }
                // err is usually "NotFoundException" while scanning – safe to ignore
            });

            // Save the stream from the video element once it starts
            setTimeout(() => {
                if (video && video.srcObject) {
                    currentStream = video.srcObject;
                }
            }, 500);

        } catch (e) {
            console.error('Error starting decode:', e);
            alert('Unable to start camera. Please check permissions.');
            closeScanner();
        }
    }

    function pickBackCameraIndex(devices) {
        // Prefer labels that look like back/rear/environment
        let idx = devices.findIndex(d =>
            /back|rear|environment/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Next, prefer anything that is NOT clearly front
        idx = devices.findIndex(d =>
            !/front|user/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Fallback to first
        return 0;
    }

    async function openScanner(inputId) {
        selectedInputId = inputId;
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'flex';

        if (!codeReader) {
            codeReader = new ZXing.BrowserMultiFormatReader();
        }

        try {
            // Get list of cameras
            videoInputDevices = await codeReader.listVideoInputDevices();
            if (!videoInputDevices.length) {
                alert('No camera found on this device.');
                closeScanner();
                return;
            }

            currentDeviceIndex = pickBackCameraIndex(videoInputDevices);
            await startDecodingWithCurrentDevice();
        } catch (e) {
            console.error(e);
            alert('Unable to access camera. Please check permissions.');
            closeScanner();
        }
    }

    function switchCamera() {
        if (!videoInputDevices.length || !codeReader) return;

        try {
            codeReader.reset();
        } catch (e) {
            console.warn(e);
        }
        stopCurrentStream();

        currentDeviceIndex = (currentDeviceIndex + 1) % videoInputDevices.length;
        startDecodingWithCurrentDevice();
    }

    function closeScanner() {
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'none';

        if (codeReader) {
            try {
                codeReader.reset();
            } catch (e) {
                console.warn(e);
            }
        }
        stopCurrentStream();
    }
</script>
</body>
</html>
If you hit any weirdness (e.g., no history showing when you expect some), we can adjust the JOIN Inventory behavior or add a fallback.

You said:
what do you mean?
ChatGPT said:

Thought for 18s
Yeah, sorry, that last line was a little hand-wavy 😅
Here’s what I meant in plain English.

In the History section we’re doing this:

FROM AuditLog a
JOIN Inventory i
  ON i.BatteryID = a.BatteryID
 AND i.StockType = 'BATTERY'
WHERE a.EmployeeID = :empId
  AND a.Type NOT IN ('Log On', 'Receive')
Because of that JOIN Inventory ... AND i.StockType = 'BATTERY':

You will only see history rows where:

The BatteryID in AuditLog still exists in Inventory, and

That Inventory row currently has StockType = 'BATTERY'.

So “weirdness” would look like:

You know there’s an AuditLog row (e.g., you can see it in SQL),
but it doesn’t show up in History because:

The Inventory row for that BatteryID was deleted, or

StockType got changed to something else, or

There are multiple inventory rows and the one that matches doesn’t have StockType = 'BATTERY'.

If that’s acceptable (only show events for batteries that are still in Inventory as StockType BATTERY), then we’re good.

If instead you want every relevant AuditLog row for that employee (even if the Inventory row is gone or StockType changed), we’d need to:

Either drop the JOIN and trust that these AuditLog rows are all batteries, or

Store StockType in AuditLog at the time of the event and filter on that instead of joining Inventory.

Right now the code I gave you is the stricter version (only current StockType BATTERY). That’s all I meant by “if you hit weirdness, we can tweak how the JOIN works.”

You said:
Oh... no don't join on Inventory table, just the auditlog. There is a field on there called StockType as well
ChatGPT said:

Thought for 14s
Gotcha—that makes sense now. Here’s the full updated build with:

✅ History filtered only on AuditLog

✅ AuditLog.StockType = 'BATTERY'

✅ Excludes Type IN ('Log On','Receive')

✅ History header uses the employee name, not AAA

<?php
// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
    // Clear session data
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        // Session expired
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    } else {
        // Still active, refresh timer
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE (NO OUTPUT BEFORE THIS POINT) =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Please enter both AAA and Password.";
        } else {
            try {
                $dsnLogin = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
                $pdoLogin = new PDO($dsnLogin, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "
                    SELECT AAA, FirstName, LastName
                    FROM Employee
                    WHERE AAA = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA'];
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', '', 'MOBILE', :lastUpdate, 'BATTERY')
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']     = true;
                    $_SESSION['empAAA']        = $empId;
                    $_SESSION['empFirst']      = $emp['FirstName'];
                    $_SESSION['empLast']       = $emp['LastName'];
                    $_SESSION['empName']       = $empName;
                    $_SESSION['last_activity'] = time(); // start timeout timer

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }

            } catch (Exception $e) {
                $error = "Login error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Browns Towing Battery Program Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2 style="text-align:center;">Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p style="color:orange;">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>AAA:</label><br>
            <input type="text" name="aaa"
                   style="width:100%; padding:8px; margin:8px 0;"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

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

// ===== WE HAVE A LOGGED IN USER =====
$empAAA  = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName = $_SESSION['empName'] ?? 'Tuna Marie';

// ===== CONNECT TO DB =====
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE =====
// views: menu | inventory | sell | transfer | scrap | stocktruck | history
$view = $_GET['view'] ?? 'menu';
$msg  = $_GET['msg']  ?? '';

// Predeclare vars
$invRows        = [];
$allLocations   = [];
$allBatteries   = [];

$sellError      = "";
$sellInfo       = null;

$transferError      = "";
$transferSuccess    = "";
$transferPreview    = null;
$transferToLoc      = "";
$transferBatteryId  = "";
$destRows           = [];

$scrapError      = "";
$scrapInfo       = null;

$historyRows     = [];

$soldTodayCount  = 0;

// ===== INVENTORY SECTION =====
if ($view === 'inventory') {
    $selectedLocation = isset($_GET['loc']) ? trim($_GET['loc']) : '';
    $selectedBattery  = isset($_GET['bat']) ? trim($_GET['bat']) : '';

    // Dropdown data (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sqlAll = "
        SELECT 
            Inventory.Battery AS Battery,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
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

    // Aggregated query (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sql = "
        SELECT 
            Inventory.Battery AS Battery,
            COUNT(*) AS Quantity,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
    ";
    $params = [];

    if ($selectedLocation !== '') {
        $sql .= " AND Inventory.Location = :loc";
        $params[':loc'] = $selectedLocation;
    }
    if ($selectedBattery !== '') {
        $sql .= " AND Inventory.Battery = :bat";
        $params[':bat'] = $selectedBattery;
    }

    $sql .= "
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== SELL BATTERY SECTION =====
if ($view === 'sell') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $sellError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm sale
        elseif (isset($_POST['confirm_sell'])) {
            $bid = trim($_POST['battery_id'] ?? '');

            if ($bid === '') {
                $sellError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $fromLoc = $row['Location'];
                        $now     = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory 
                            SET Location = 'SOLD' 
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType)
                            VALUES
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate, 'BATTERY')
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':batteryId'  => $row['BatteryID'],
                            ':battery'    => $row['Battery'],
                            ':dateCode'   => $row['DateCode'],
                            ':lastUpdate' => $now,
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

// ===== TRANSFER BATTERY SECTION =====
if ($view === 'transfer') {
    // Build combined destination list: shops + trucks
    $stmtDest = $pdo->query("
        SELECT Location AS ToLoc, 'SHOP' AS Type
        FROM Location
        UNION ALL
        SELECT Truck AS ToLoc, 'TRUCK' AS Type
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
    ");
    $rowsDest = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($rowsDest as $r) {
        $loc  = trim($r['ToLoc'] ?? '');
        $type = $r['Type'] ?? 'TRUCK';
        if ($loc === '') continue;
        if (!isset($seen[$loc])) {
            $seen[$loc] = $type;
        }
    }

    foreach ($seen as $loc => $type) {
        $destRows[] = [
            'ToLoc' => $loc,
            'Type'  => $type
        ];
    }

    // Sort: shops first, then trucks; each group descending by name
    usort($destRows, function($a, $b) {
        if ($a['Type'] !== $b['Type']) {
            return ($a['Type'] === 'SHOP') ? -1 : 1;
        }
        return strcasecmp($b['ToLoc'], $a['ToLoc']);
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Preview transfer
        if (isset($_POST['preview_transfer'])) {
            $bid        = trim($_POST['battery_id'] ?? '');
            $transferTo = trim($_POST['to_loc'] ?? '');
            $transferToLoc     = $transferTo;
            $transferBatteryId = $bid;

            if ($bid === '') {
                $transferError = "Please enter a BatteryID.";
            } elseif ($transferTo === '') {
                $transferError = "Please select a destination.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED.";
                } else {
                    $fromLoc = $row['Location'];

                    if ($fromLoc === $transferTo) {
                        $transferError = "BatteryID already at location.";
                    } else {
                        $transferPreview = [
                            'BatteryID' => $row['BatteryID'],
                            'Battery'   => $row['Battery'],
                            'DateCode'  => $row['DateCode'],
                            'FromLoc'   => $fromLoc,
                            'ToLoc'     => $transferTo,
                        ];
                    }
                }
            }
        }

        // Step 2: Confirm transfer
        elseif (isset($_POST['confirm_transfer'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $fromLoc   = trim($_POST['from_loc'] ?? '');
            $toLoc     = trim($_POST['to_loc'] ?? '');
            $battery   = trim($_POST['battery'] ?? '');
            $dateCode  = trim($_POST['date_code'] ?? '');

            // keep UI selections
            $transferToLoc     = $toLoc;
            $transferBatteryId = $bid;

            if ($bid === '' || $fromLoc === '' || $toLoc === '') {
                $transferError = "Missing transfer data. Please try again.";
            } elseif ($fromLoc === $toLoc) {
                $transferError = "BatteryID already at location.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $check = $pdo->prepare("
                        SELECT Inventory.Location
                        FROM Inventory
                        WHERE BatteryID = :bid
                          AND Location NOT IN ('SOLD','SCRAPPED')
                          AND StockType = 'BATTERY'
                    ");
                    $check->execute([':bid' => $bid]);
                    $current = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$current || $current['Location'] !== $fromLoc) {
                        $pdo->rollBack();
                        $transferError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                    } else {
                        $now = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :toLoc
                            WHERE BatteryID = :bid
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([
                            ':toLoc' => $toLoc,
                            ':bid'   => $bid
                        ]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', :lastUpdate, 'BATTERY')
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':toLoc'      => $toLoc,
                            ':batteryId'  => $bid,
                            ':battery'    => $battery,
                            ':dateCode'   => $dateCode,
                            ':lastUpdate' => $now,
                        ]);

                        $pdo->commit();

                        // Stay on Transfer page and show success
                        $transferSuccess   = "Battery was successfully transferred and logged.";
                        $transferPreview   = null;
                        $transferBatteryId = ""; // clear BatteryID prompt after successful transfer
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $transferError = "Error transferring battery: " . $e->getMessage();
                }
            }
        }
    }
}

// ===== SCRAP BATTERY SECTION =====
if ($view === 'scrap') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup battery
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $scrapError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $scrapInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$scrapInfo) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm scrap
        elseif (isset($_POST['confirm_scrap'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $reasonRaw = $_POST['reason'] ?? '';

            // We’ll requery battery info for safety
            if ($bid === '') {
                $scrapError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    // Validate reason
                    $reasonTrim  = trim($reasonRaw);
                    if ($reasonTrim === '') {
                        $scrapError = "Reason is required to scrap a battery.";
                        $scrapInfo  = $row; // keep display
                    } else {
                        // Clean reason: remove single quotes & limit to 255 chars
                        $reasonClean = str_replace("'", "", $reasonTrim);
                        $reasonClean = mb_substr($reasonClean, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $fromLoc = $row['Location'];
                            $now     = date('Y-m-d H:i:s');

                            // Update Inventory to SCRAPPED
                            $update = $pdo->prepare("
                                UPDATE Inventory 
                                SET Location = 'SCRAPPED'
                                WHERE BatteryID = :bid
                                  AND Location NOT IN ('SOLD','SCRAPPED')
                                  AND StockType = 'BATTERY'
                            ");
                            $update->execute([':bid' => $bid]);

                            // Insert AuditLog (ToLoc = SCRAPPED, Reason = user text)
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType)
                                VALUES
                                    (:empId, :empName, :fromLoc, 'SCRAPPED', :batteryId, 'Scrap', '', :battery, :dateCode, :reason, :fromLoc, 'MOBILE', :lastUpdate, 'BATTERY')
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':batteryId'  => $row['BatteryID'],
                                ':battery'    => $row['Battery'],
                                ':dateCode'   => $row['DateCode'],
                                ':reason'     => $reasonClean,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=scrapped");
                            exit;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $scrapError = "Error scrapping battery: " . $e->getMessage();
                            $scrapInfo  = $row;
                        }
                    }
                }
            }
        }
    }
}

// ===== HISTORY SECTION =====
if ($view === 'history') {
    try {
        $stmtHist = $pdo->prepare("
            SELECT 
                BatteryID,
                Battery,
                Type,
                ToLoc,
                FromLoc,
                LastUpdate
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND StockType = 'BATTERY'
              AND Type NOT IN ('Log On', 'Receive')
            ORDER BY LastUpdate DESC
            LIMIT 25
        ");
        $stmtHist->execute([':empId' => $empAAA]);
        $historyRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $historyRows = [];
    }
}

// ===== SOLD TODAY COUNT (MENU ONLY, BASED ON EST/EDT) =====
if ($view === 'menu') {
    try {
        // Local "today" in EST/EDT
        $startToday = date('Y-m-d 00:00:00');
        $endToday   = date('Y-m-d 23:59:59');

        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND LastUpdate >= :startToday
              AND LastUpdate <= :endToday
        ");
        $stmtSold->execute([
            ':empId'      => $empAAA,
            ':startToday' => $startToday,
            ':endToday'   => $endToday,
        ]);
        $soldTodayCount = (int)$stmtSold->fetchColumn();
    } catch (Exception $e) {
        $soldTodayCount = 0; // fail quietly
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
                grid-template-columns: repeat(4, 1fr);
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
        select, input[type="text"], textarea {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        textarea {
            min-height: 80px;
            resize: vertical;
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
        .mt-6  { margin-top: 6px; }
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php elseif ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php elseif ($msg === 'scrapped'): ?>
        <div class="msg msg-success">
            Battery was successfully scrapped and logged.
        </div>
    <?php endif; ?>

    <?php if ($view === 'menu'): ?>

        <h2>Main Menu</h2>
        <div class="menu-grid">
            <a class="btn" href="?view=inventory">Inventory</a>
            <a class="btn" href="?view=sell">Sell Battery</a>
            <a class="btn" href="?view=stocktruck">Stock Truck</a>
            <a class="btn" href="?view=transfer">Transfer Battery</a>
            <a class="btn" href="?view=scrap">Scrap Battery</a>
            <a class="btn" href="?view=history">History</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong> (<?= htmlspecialchars($empAAA) ?>).<br>
                Use the buttons above to manage batteries.
            </p>

            <?php if ($soldTodayCount > 0): ?>
                <p class="text-center" style="font-size:13px; color:#166534; margin-top:6px;">
                    You have sold <strong><?= $soldTodayCount ?></strong>
                    battery<?= ($soldTodayCount === 1 ? '' : 'ies') ?> so far today.
                </p>
            <?php endif; ?>

            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary" style="max-width:200px; display:inline-block;">
                    Logout
                </a>
            </div>
        </div>

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

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="sell_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('sell_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not previously <strong>SOLD</strong> or <strong>SCRAPPED</strong> are eligible.
            </p>
        </div>

        <!-- Step 2: Confirm Sale -->
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

        <?php if (!empty($transferSuccess)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($transferSuccess) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: BatteryID + Destination -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="transfer_battery_id"
                           style="flex:1;"
                           value="<?= htmlspecialchars($transferBatteryId ?? '') ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('transfer_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <label class="label-block mt-10">Transfer To</label>
                <select name="to_loc">
                    <option value="">Select Destination</option>
                    <?php foreach ($destRows as $d): ?>
                        <?php
                        $label = $d['ToLoc'] . ' (' . $d['Type'] . ')';
                        $val   = $d['ToLoc'];
                        ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                            <?= ($val === ($transferToLoc ?? '')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="preview_transfer" class="btn mt-10">
                    Preview Transfer
                </button>
            </form>

            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be transferred.
            </p>
        </div>

        <!-- Step 2: Preview + Confirm -->
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

    <?php elseif ($view === 'scrap'): ?>

        <h2>Scrap a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($scrapError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($scrapError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="scrap_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('scrap_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be scrapped.
            </p>
        </div>

        <!-- Step 2: Confirm Scrap + Reason -->
        <?php if ($scrapInfo): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Scrap</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($scrapInfo['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($scrapInfo['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($scrapInfo['DateCode']) ?></p>
                <p><strong>Current Location:</strong> <?= htmlspecialchars($scrapInfo['Location']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($scrapInfo['BatteryID']) ?>">

                    <label class="label-block">Reason for Scrap (required, max 255 chars)</label>
                    <textarea name="reason" maxlength="255"
                              placeholder="Describe why this battery is being scrapped."><?= isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : '' ?></textarea>

                    <button type="submit" name="confirm_scrap" class="btn mt-10">
                        Scrap This Battery
                    </button>
                </form>

                <p class="mt-6 small-note">
                    Reason text will be cleaned (for example, single quotes removed) before being stored in the audit log.
                </p>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'history'): ?>

        <h2>History</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="small-note">
                Showing the most recent 25 events for <strong><?= htmlspecialchars($empName) ?></strong>.
            </p>
            <div class="table-container">
                <table>
                    <tr>
                        <th>BatteryID</th>
                        <th>Battery</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Time/Date</th>
                    </tr>
                    <?php if (empty($historyRows)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No history found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historyRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['BatteryID']  ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Battery']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Type']       ?? '') ?></td>
                                <td><?= htmlspecialchars($row['FromLoc']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['ToLoc']      ?? '') ?></td>
                                <td><?= htmlspecialchars($row['LastUpdate'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    <?php elseif ($view === 'stocktruck'): ?>

        <h2>Stock Truck</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:14px;">
                Stock Truck functionality coming soon.
            </p>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Barcode Scanner Modal -->
<div id="scannerOverlay" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.8);
    z-index:9999;
    align-items:center;
    justify-content:center;
">
    <div style="background:#fff; padding:10px; border-radius:8px; max-width:400px; width:90%; text-align:center;">
        <h3 style="margin-top:0;">Scan Battery Barcode</h3>
        <video id="scannerVideo" style="width:100%; max-height:300px; background:#000;"></video>
        <p style="font-size:12px; color:#6b7280; margin-top:6px;">
            Align the barcode within the frame until it is detected.
        </p>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:8px;">
            <button type="button" onclick="switchCamera()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#2563eb;
                color:#fff;
            ">
                Switch Camera
            </button>
            <button type="button" onclick="closeScanner()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#4b5563;
                color:#fff;
            ">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ZXing and scanner script -->
<script src="https://unpkg.com/@zxing/library@latest"></script>
<script>
    let selectedInputId = null;
    let codeReader = null;
    let videoInputDevices = [];
    let currentDeviceIndex = 0;
    let currentStream = null;
    let audioCtx = null;

    function playBeep() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
            }
            const duration = 0.15; // seconds
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5-ish
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    }

    function stopCurrentStream() {
        const video = document.getElementById('scannerVideo');
        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }
        if (video) {
            video.srcObject = null;
        }
    }

    async function startDecodingWithCurrentDevice() {
        if (!videoInputDevices.length) return;

        const device = videoInputDevices[currentDeviceIndex];
        const deviceId = device.deviceId;

        stopCurrentStream();

        try {
            const video = document.getElementById('scannerVideo');

            // ZXing will call getUserMedia under the hood
            codeReader.decodeFromVideoDevice(deviceId, 'scannerVideo', (result, err) => {
                if (result) {
                    const input = document.getElementById(selectedInputId);
                    if (input) {
                        input.value = result.text;
                    }
                    playBeep();
                    closeScanner();
                }
                // err is usually "NotFoundException" while scanning – safe to ignore
            });

            // Save the stream from the video element once it starts
            setTimeout(() => {
                if (video && video.srcObject) {
                    currentStream = video.srcObject;
                }
            }, 500);

        } catch (e) {
            console.error('Error starting decode:', e);
            alert('Unable to start camera. Please check permissions.');
            closeScanner();
        }
    }

    function pickBackCameraIndex(devices) {
        // Prefer labels that look like back/rear/environment
        let idx = devices.findIndex(d =>
            /back|rear|environment/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Next, prefer anything that is NOT clearly front
        idx = devices.findIndex(d =>
            !/front|user/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Fallback to first
        return 0;
    }

    async function openScanner(inputId) {
        selectedInputId = inputId;
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'flex';

        if (!codeReader) {
            codeReader = new ZXing.BrowserMultiFormatReader();
        }

        try {
            // Get list of cameras
            videoInputDevices = await codeReader.listVideoInputDevices();
            if (!videoInputDevices.length) {
                alert('No camera found on this device.');
                closeScanner();
                return;
            }

            currentDeviceIndex = pickBackCameraIndex(videoInputDevices);
            await startDecodingWithCurrentDevice();
        } catch (e) {
            console.error(e);
            alert('Unable to access camera. Please check permissions.');
            closeScanner();
        }
    }

    function switchCamera() {
        if (!videoInputDevices.length || !codeReader) return;

        try {
            codeReader.reset();
        } catch (e) {
            console.warn(e);
        }
        stopCurrentStream();

        currentDeviceIndex = (currentDeviceIndex + 1) % videoInputDevices.length;
        startDecodingWithCurrentDevice();
    }

    function closeScanner() {
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'none';

        if (codeReader) {
            try {
                codeReader.reset();
            } catch (e) {
                console.warn(e);
            }
        }
        stopCurrentStream();
    }
</script>
</body>
</html>
If you want next: we can add filters on History (date range, type = Transfer / Scrap / Sale only, etc.), but as-is this should do exactly what you described.

You said:
Great now I need to do the Stock Truck Part
They need to select a truck from the table Trucks.Truck

Then Show the results of this SQL

SELECT 
    Battery,
    Current,
    Min,
    Need
FROM (
    SELECT 
        bm.Location,
        bm.Battery,
        COUNT(inv.BatteryID) AS Current,
        bm.Minimum AS Min,
        (COUNT(inv.BatteryID) - bm.Minimum)*-1 AS Need
    FROM BatteryMinimum bm
    LEFT JOIN Inventory inv
        ON inv.Location = bm.Location
        AND inv.Battery = bm.Battery
    WHERE bm.Location = <Truck>
    GROUP BY bm.Location, bm.Battery, bm.Minimum

    UNION ALL

    SELECT
        inv.Location,
        inv.Battery,
        COUNT(inv.BatteryID) AS Current,
        0 AS Min,
        COUNT(inv.BatteryID)*-1 AS Need
    FROM Inventory inv
    LEFT JOIN BatteryMinimum bm
        ON bm.Location = inv.Location
        AND bm.Battery = inv.Battery
    WHERE inv.Location = <Truck>
      AND bm.Battery IS NULL
    GROUP BY inv.Location, inv.Battery
) AS x
WHERE Need <> 0
ORDER BY Battery;

There needs to be an option to clear truck
if they choose that option they need to select the Shop it goes to from the Location.Location table
Warn them that continuing will Clear their truck inventory. If they do Then update Inventory table where location = <truck> to <shop location>. Then insert a record into the Audit log for each battery
Employeeid = <AAA>
Employee = <Name>
FromLoc = <truck>
ToLoc = <shop location>
BatteryID = Location.BatteryID
Type = 'ClearTruck'
Battery = Location.Battery
DateCode = Location.DateCode
Location = 'MOBILE'
Computer = 'MOBILE'
StockType = 'Battery'
Quantity = 1

Also please edit all auditlog inserts where Auditlog.Location is used and Change it to always be 'MOBILE'. 
ChatGPT said:

Thought for 2m 23s
Nice, this is a fun one. 😄
Below is the full updated build with:

✅ Stock Truck fully implemented:

Truck dropdown from Trucks.Truck

“Show Truck Stock” runs your supplied SQL (adapted for Inventory + StockType='BATTERY')

Results table: Battery, Current, Min, Need

“Clear Truck” panel:

Requires a Shop from Location.Location

Warns user that it will clear truck inventory

Moves all Inventory rows for that truck to the selected shop

Writes one AuditLog row per battery with:

Type = 'ClearTruck'

FromLoc = <truck>

ToLoc = <shop>

Location = 'MOBILE'

StockType = 'BATTERY'

Quantity = 1

✅ All AuditLog inserts updated so Location is always 'MOBILE'.

<?php
// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
    // Clear session data
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        // Session expired
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    } else {
        // Still active, refresh timer
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE (NO OUTPUT BEFORE THIS POINT) =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Please enter both AAA and Password.";
        } else {
            try {
                $dsnLogin = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
                $pdoLogin = new PDO($dsnLogin, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "
                    SELECT AAA, FirstName, LastName
                    FROM Employee
                    WHERE AAA = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA'];
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY')
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']     = true;
                    $_SESSION['empAAA']        = $empId;
                    $_SESSION['empFirst']      = $emp['FirstName'];
                    $_SESSION['empLast']       = $emp['LastName'];
                    $_SESSION['empName']       = $empName;
                    $_SESSION['last_activity'] = time(); // start timeout timer

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }

            } catch (Exception $e) {
                $error = "Login error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Browns Towing Battery Program Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2 style="text-align:center;">Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p style="color:orange;">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>AAA:</label><br>
            <input type="text" name="aaa"
                   style="width:100%; padding:8px; margin:8px 0;"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

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

// ===== WE HAVE A LOGGED IN USER =====
$empAAA  = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName = $_SESSION['empName'] ?? 'Tuna Marie';

// ===== CONNECT TO DB =====
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE =====
// views: menu | inventory | sell | transfer | scrap | stocktruck | history
$view = $_GET['view'] ?? 'menu';
$msg  = $_GET['msg']  ?? '';

// Predeclare vars
$invRows        = [];
$allLocations   = [];
$allBatteries   = [];

$sellError      = "";
$sellInfo       = null;

$transferError      = "";
$transferSuccess    = "";
$transferPreview    = null;
$transferToLoc      = "";
$transferBatteryId  = "";
$destRows           = [];

$scrapError      = "";
$scrapInfo       = null;

$historyRows     = [];

$soldTodayCount  = 0;

// Stock Truck
$stockTruckError          = "";
$stockTruckMessage        = "";
$stockTruckSelectedTruck  = "";
$stockTruckSelectedShop   = "";
$stockTruckRows           = [];
$stockTruckTruckList      = [];
$stockTruckShopList       = [];

// ===== INVENTORY SECTION =====
if ($view === 'inventory') {
    $selectedLocation = isset($_GET['loc']) ? trim($_GET['loc']) : '';
    $selectedBattery  = isset($_GET['bat']) ? trim($_GET['bat']) : '';

    // Dropdown data (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sqlAll = "
        SELECT 
            Inventory.Battery AS Battery,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
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

    // Aggregated query (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sql = "
        SELECT 
            Inventory.Battery AS Battery,
            COUNT(*) AS Quantity,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
    ";
    $params = [];

    if ($selectedLocation !== '') {
        $sql .= " AND Inventory.Location = :loc";
        $params[':loc'] = $selectedLocation;
    }
    if ($selectedBattery !== '') {
        $sql .= " AND Inventory.Battery = :bat";
        $params[':bat'] = $selectedBattery;
    }

    $sql .= "
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== SELL BATTERY SECTION =====
if ($view === 'sell') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $sellError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm sale
        elseif (isset($_POST['confirm_sell'])) {
            $bid = trim($_POST['battery_id'] ?? '');

            if ($bid === '') {
                $sellError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $fromLoc = $row['Location'];
                        $now     = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory 
                            SET Location = 'SOLD' 
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType)
                            VALUES
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY')
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':batteryId'  => $row['BatteryID'],
                            ':battery'    => $row['Battery'],
                            ':dateCode'   => $row['DateCode'],
                            ':lastUpdate' => $now,
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

// ===== TRANSFER BATTERY SECTION =====
if ($view === 'transfer') {
    // Build combined destination list: shops + trucks
    $stmtDest = $pdo->query("
        SELECT Location AS ToLoc, 'SHOP' AS Type
        FROM Location
        UNION ALL
        SELECT Truck AS ToLoc, 'TRUCK' AS Type
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
    ");
    $rowsDest = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($rowsDest as $r) {
        $loc  = trim($r['ToLoc'] ?? '');
        $type = $r['Type'] ?? 'TRUCK';
        if ($loc === '') continue;
        if (!isset($seen[$loc])) {
            $seen[$loc] = $type;
        }
    }

    foreach ($seen as $loc => $type) {
        $destRows[] = [
            'ToLoc' => $loc,
            'Type'  => $type
        ];
    }

    // Sort: shops first, then trucks; each group descending by name
    usort($destRows, function($a, $b) {
        if ($a['Type'] !== $b['Type']) {
            return ($a['Type'] === 'SHOP') ? -1 : 1;
        }
        return strcasecmp($b['ToLoc'], $a['ToLoc']);
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Preview transfer
        if (isset($_POST['preview_transfer'])) {
            $bid        = trim($_POST['battery_id'] ?? '');
            $transferTo = trim($_POST['to_loc'] ?? '');
            $transferToLoc     = $transferTo;
            $transferBatteryId = $bid;

            if ($bid === '') {
                $transferError = "Please enter a BatteryID.";
            } elseif ($transferTo === '') {
                $transferError = "Please select a destination.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED.";
                } else {
                    $fromLoc = $row['Location'];

                    if ($fromLoc === $transferTo) {
                        $transferError = "BatteryID already at location.";
                    } else {
                        $transferPreview = [
                            'BatteryID' => $row['BatteryID'],
                            'Battery'   => $row['Battery'],
                            'DateCode'  => $row['DateCode'],
                            'FromLoc'   => $fromLoc,
                            'ToLoc'     => $transferTo,
                        ];
                    }
                }
            }
        }

        // Step 2: Confirm transfer
        elseif (isset($_POST['confirm_transfer'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $fromLoc   = trim($_POST['from_loc'] ?? '');
            $toLoc     = trim($_POST['to_loc'] ?? '');
            $battery   = trim($_POST['battery'] ?? '');
            $dateCode  = trim($_POST['date_code'] ?? '');

            // keep UI selections
            $transferToLoc     = $toLoc;
            $transferBatteryId = $bid;

            if ($bid === '' || $fromLoc === '' || $toLoc === '') {
                $transferError = "Missing transfer data. Please try again.";
            } elseif ($fromLoc === $toLoc) {
                $transferError = "BatteryID already at location.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $check = $pdo->prepare("
                        SELECT Inventory.Location
                        FROM Inventory
                        WHERE BatteryID = :bid
                          AND Location NOT IN ('SOLD','SCRAPPED')
                          AND StockType = 'BATTERY'
                    ");
                    $check->execute([':bid' => $bid]);
                    $current = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$current || $current['Location'] !== $fromLoc) {
                        $pdo->rollBack();
                        $transferError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                    } else {
                        $now = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :toLoc
                            WHERE BatteryID = :bid
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([
                            ':toLoc' => $toLoc,
                            ':bid'   => $bid
                        ]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY')
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':toLoc'      => $toLoc,
                            ':batteryId'  => $bid,
                            ':battery'    => $battery,
                            ':dateCode'   => $dateCode,
                            ':lastUpdate' => $now,
                        ]);

                        $pdo->commit();

                        // Stay on Transfer page and show success
                        $transferSuccess   = "Battery was successfully transferred and logged.";
                        $transferPreview   = null;
                        $transferBatteryId = ""; // clear BatteryID prompt after successful transfer
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $transferError = "Error transferring battery: " . $e->getMessage();
                }
            }
        }
    }
}

// ===== SCRAP BATTERY SECTION =====
if ($view === 'scrap') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup battery
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $scrapError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $scrapInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$scrapInfo) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm scrap
        elseif (isset($_POST['confirm_scrap'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $reasonRaw = $_POST['reason'] ?? '';

            // We’ll requery battery info for safety
            if ($bid === '') {
                $scrapError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    // Validate reason
                    $reasonTrim  = trim($reasonRaw);
                    if ($reasonTrim === '') {
                        $scrapError = "Reason is required to scrap a battery.";
                        $scrapInfo  = $row; // keep display
                    } else {
                        // Clean reason: remove single quotes & limit to 255 chars
                        $reasonClean = str_replace("'", "", $reasonTrim);
                        $reasonClean = mb_substr($reasonClean, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $fromLoc = $row['Location'];
                            $now     = date('Y-m-d H:i:s');

                            // Update Inventory to SCRAPPED
                            $update = $pdo->prepare("
                                UPDATE Inventory 
                                SET Location = 'SCRAPPED'
                                WHERE BatteryID = :bid
                                  AND Location NOT IN ('SOLD','SCRAPPED')
                                  AND StockType = 'BATTERY'
                            ");
                            $update->execute([':bid' => $bid]);

                            // Insert AuditLog (ToLoc = SCRAPPED, Reason = user text)
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType)
                                VALUES
                                    (:empId, :empName, :fromLoc, 'SCRAPPED', :batteryId, 'Scrap', '', :battery, :dateCode, :reason, 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY')
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':batteryId'  => $row['BatteryID'],
                                ':battery'    => $row['Battery'],
                                ':dateCode'   => $row['DateCode'],
                                ':reason'     => $reasonClean,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=scrapped");
                            exit;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $scrapError = "Error scrapping battery: " . $e->getMessage();
                            $scrapInfo  = $row;
                        }
                    }
                }
            }
        }
    }
}

// ===== STOCK TRUCK SECTION =====
if ($view === 'stocktruck') {
    // Load truck list (from Trucks.Truck)
    $stmtTrucks = $pdo->query("
        SELECT Truck
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
        ORDER BY Truck
    ");
    $stockTruckTruckList = $stmtTrucks->fetchAll(PDO::FETCH_COLUMN);

    // Load shop list (from Location.Location)
    $stmtShops = $pdo->query("
        SELECT Location
        FROM Location
        WHERE Location IS NOT NULL AND Location <> ''
        ORDER BY Location
    ");
    $stockTruckShopList = $stmtShops->fetchAll(PDO::FETCH_COLUMN);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $stockTruckSelectedTruck = trim($_POST['truck'] ?? '');
        $stockTruckSelectedShop  = trim($_POST['shop_loc'] ?? '');

        // Show truck stock
        if (isset($_POST['show_truck'])) {
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck.";
            } else {
                $sqlTruck = "
                    SELECT 
                        Battery,
                        Current,
                        Min,
                        Need
                    FROM (
                        SELECT 
                            bm.Location,
                            bm.Battery,
                            COUNT(inv.BatteryID) AS Current,
                            bm.Minimum AS Min,
                            (COUNT(inv.BatteryID) - bm.Minimum) * -1 AS Need
                        FROM BatteryMinimum bm
                        LEFT JOIN Inventory inv
                            ON inv.Location = bm.Location
                            AND inv.Battery = bm.Battery
                            AND inv.StockType = 'BATTERY'
                        WHERE bm.Location = :truck
                        GROUP BY bm.Location, bm.Battery, bm.Minimum

                        UNION ALL

                        SELECT
                            inv.Location,
                            inv.Battery,
                            COUNT(inv.BatteryID) AS Current,
                            0 AS Min,
                            COUNT(inv.BatteryID) * -1 AS Need
                        FROM Inventory inv
                        LEFT JOIN BatteryMinimum bm
                            ON bm.Location = inv.Location
                            AND bm.Battery = inv.Battery
                        WHERE inv.Location = :truck
                          AND inv.StockType = 'BATTERY'
                          AND bm.Battery IS NULL
                        GROUP BY inv.Location, inv.Battery
                    ) AS x
                    WHERE Need <> 0
                    ORDER BY Battery
                ";
                $stmtTruck = $pdo->prepare($sqlTruck);
                $stmtTruck->execute([':truck' => $stockTruckSelectedTruck]);
                $stockTruckRows = $stmtTruck->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        // Clear truck inventory
        if (isset($_POST['clear_truck'])) {
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck before clearing.";
            } elseif ($stockTruckSelectedShop === '') {
                $stockTruckError = "Please select a shop to move inventory to.";
            } else {
                try {
                    $pdo->beginTransaction();

                    // Select all battery inventory on that truck
                    $selInv = $pdo->prepare("
                        SELECT BatteryID, Battery, DateCode
                        FROM Inventory
                        WHERE Location = :truck
                          AND StockType = 'BATTERY'
                    ");
                    $selInv->execute([':truck' => $stockTruckSelectedTruck]);
                    $truckInvRows = $selInv->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($truckInvRows)) {
                        $pdo->rollBack();
                        $stockTruckError = "No battery inventory found on truck " . $stockTruckSelectedTruck . ".";
                    } else {
                        // Move inventory to selected shop
                        $updInv = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :shop
                            WHERE Location = :truck
                              AND StockType = 'BATTERY'
                        ");
                        $updInv->execute([
                            ':shop'  => $stockTruckSelectedShop,
                            ':truck' => $stockTruckSelectedTruck
                        ]);

                        // Insert one AuditLog record per battery
                        $insAudit = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'ClearTruck', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                        ");

                        $now = date('Y-m-d H:i:s');

                        foreach ($truckInvRows as $r) {
                            $insAudit->execute([
                                ':empId'     => $empAAA,
                                ':empName'   => $empName,
                                ':fromLoc'   => $stockTruckSelectedTruck,
                                ':toLoc'     => $stockTruckSelectedShop,
                                ':batteryId' => $r['BatteryID'],
                                ':battery'   => $r['Battery'],
                                ':dateCode'  => $r['DateCode'],
                                ':lastUpdate'=> $now,
                            ]);
                        }

                        $pdo->commit();

                        $stockTruckMessage = "Truck " . $stockTruckSelectedTruck .
                            " inventory successfully moved to " . $stockTruckSelectedShop . ".";
                        $stockTruckRows = []; // truck is now cleared
                    }

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $stockTruckError = "Error clearing truck: " . $e->getMessage();
                }
            }
        }
    }
}

// ===== HISTORY SECTION =====
if ($view === 'history') {
    try {
        $stmtHist = $pdo->prepare("
            SELECT 
                BatteryID,
                Battery,
                Type,
                ToLoc,
                FromLoc,
                LastUpdate
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND StockType = 'BATTERY'
              AND Type NOT IN ('Log On', 'Receive')
            ORDER BY LastUpdate DESC
            LIMIT 25
        ");
        $stmtHist->execute([':empId' => $empAAA]);
        $historyRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $historyRows = [];
    }
}

// ===== SOLD TODAY COUNT (MENU ONLY, BASED ON EST/EDT) =====
if ($view === 'menu') {
    try {
        // Local "today" in EST/EDT
        $startToday = date('Y-m-d 00:00:00');
        $endToday   = date('Y-m-d 23:59:59');

        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND LastUpdate >= :startToday
              AND LastUpdate <= :endToday
        ");
        $stmtSold->execute([
            ':empId'      => $empAAA,
            ':startToday' => $startToday,
            ':endToday'   => $endToday,
        ]);
        $soldTodayCount = (int)$stmtSold->fetchColumn();
    } catch (Exception $e) {
        $soldTodayCount = 0; // fail quietly
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
                grid-template-columns: repeat(4, 1fr);
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
        select, input[type="text"], textarea {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        textarea {
            min-height: 80px;
            resize: vertical;
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
        .mt-6  { margin-top: 6px; }
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php elseif ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php elseif ($msg === 'scrapped'): ?>
        <div class="msg msg-success">
            Battery was successfully scrapped and logged.
        </div>
    <?php endif; ?>

    <?php if ($view === 'menu'): ?>

        <h2>Main Menu</h2>
        <div class="menu-grid">
            <a class="btn" href="?view=inventory">Inventory</a>
            <a class="btn" href="?view=sell">Sell Battery</a>
            <a class="btn" href="?view=stocktruck">Stock Truck</a>
            <a class="btn" href="?view=transfer">Transfer Battery</a>
            <a class="btn" href="?view=scrap">Scrap Battery</a>
            <a class="btn" href="?view=history">History</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong> (<?= htmlspecialchars($empAAA) ?>).<br>
                Use the buttons above to manage batteries.
            </p>

            <?php if ($soldTodayCount > 0): ?>
                <p class="text-center" style="font-size:13px; color:#166534; margin-top:6px;">
                    You have sold <strong><?= $soldTodayCount ?></strong>
                    battery<?= ($soldTodayCount === 1 ? '' : 'ies') ?> so far today.
                </p>
            <?php endif; ?>

            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary" style="max-width:200px; display:inline-block;">
                    Logout
                </a>
            </div>
        </div>

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

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="sell_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('sell_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not previously <strong>SOLD</strong> or <strong>SCRAPPED</strong> are eligible.
            </p>
        </div>

        <!-- Step 2: Confirm Sale -->
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

        <?php if (!empty($transferSuccess)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($transferSuccess) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: BatteryID + Destination -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="transfer_battery_id"
                           style="flex:1;"
                           value="<?= htmlspecialchars($transferBatteryId ?? '') ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('transfer_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <label class="label-block mt-10">Transfer To</label>
                <select name="to_loc">
                    <option value="">Select Destination</option>
                    <?php foreach ($destRows as $d): ?>
                        <?php
                        $label = $d['ToLoc'] . ' (' . $d['Type'] . ')';
                        $val   = $d['ToLoc'];
                        ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                            <?= ($val === ($transferToLoc ?? '')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="preview_transfer" class="btn mt-10">
                    Preview Transfer
                </button>
            </form>

            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be transferred.
            </p>
        </div>

        <!-- Step 2: Preview + Confirm -->
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

    <?php elseif ($view === 'scrap'): ?>

        <h2>Scrap a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($scrapError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($scrapError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="scrap_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('scrap_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be scrapped.
            </p>
        </div>

        <!-- Step 2: Confirm Scrap + Reason -->
        <?php if ($scrapInfo): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Scrap</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($scrapInfo['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($scrapInfo['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($scrapInfo['DateCode']) ?></p>
                <p><strong>Current Location:</strong> <?= htmlspecialchars($scrapInfo['Location']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($scrapInfo['BatteryID']) ?>">

                    <label class="label-block">Reason for Scrap (required, max 255 chars)</label>
                    <textarea name="reason" maxlength="255"
                              placeholder="Describe why this battery is being scrapped."><?= isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : '' ?></textarea>

                    <button type="submit" name="confirm_scrap" class="btn mt-10">
                        Scrap This Battery
                    </button>
                </form>

                <p class="mt-6 small-note">
                    Reason text will be cleaned (for example, single quotes removed) before being stored in the audit log.
                </p>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'stocktruck'): ?>

        <h2>Stock Truck</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($stockTruckError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($stockTruckError) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($stockTruckMessage)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($stockTruckMessage) ?>
            </div>
        <?php endif; ?>

        <!-- Select Truck & Show Stock -->
        <div class="card">
            <form method="post">
                <label class="label-block">Truck</label>
                <select name="truck">
                    <option value="">Select Truck</option>
                    <?php foreach ($stockTruckTruckList as $truck): ?>
                        <option value="<?= htmlspecialchars($truck) ?>"
                            <?= ($truck === $stockTruckSelectedTruck ? 'selected' : '') ?>>
                            <?= htmlspecialchars($truck) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="show_truck" class="btn mt-10">
                    Show Truck Stock
                </button>
            </form>
            <p class="mt-6 small-note">
                Shows batteries where current on-truck quantity does not match the defined minimums.
            </p>
        </div>

        <!-- Stock Results -->
        <?php if (!empty($stockTruckRows)): ?>
            <div class="card">
                <h3 style="margin-top:0;">Truck Stock for <?= htmlspecialchars($stockTruckSelectedTruck) ?></h3>
                <div class="table-container">
                    <table>
                        <tr>
                            <th>Battery</th>
                            <th>Current</th>
                            <th>Min</th>
                            <th>Need</th>
                        </tr>
                        <?php foreach ($stockTruckRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['Battery'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Current'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Min'] ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Need'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Clear Truck -->
        <?php if (!empty($stockTruckTruckList)): ?>
            <div class="card">
                <h3 style="margin-top:0;">Clear Truck Inventory</h3>
                <form method="post">
                    <input type="hidden" name="truck" value="<?= htmlspecialchars($stockTruckSelectedTruck) ?>">

                    <label class="label-block">Truck</label>
                    <select name="truck">
                        <option value="">Select Truck</option>
                        <?php foreach ($stockTruckTruckList as $truck): ?>
                            <option value="<?= htmlspecialchars($truck) ?>"
                                <?= ($truck === $stockTruckSelectedTruck ? 'selected' : '') ?>>
                                <?= htmlspecialchars($truck) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label class="label-block mt-10">Move inventory to Shop</label>
                    <select name="shop_loc">
                        <option value="">Select Shop Location</option>
                        <?php foreach ($stockTruckShopList as $shop): ?>
                            <option value="<?= htmlspecialchars($shop) ?>"
                                <?= ($shop === $stockTruckSelectedShop ? 'selected' : '') ?>>
                                <?= htmlspecialchars($shop) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <p class="mt-6 small-note" style="color:#b91c1c;">
                        Warning: Continuing will move <strong>all BATTERY inventory</strong> from the selected truck
                        to the selected shop location. This action cannot be undone.
                    </p>

                    <button type="submit" name="clear_truck" class="btn mt-10">
                        Clear Truck Inventory
                    </button>
                </form>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'history'): ?>

        <h2>History</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="small-note">
                Showing the most recent 25 events for <strong><?= htmlspecialchars($empName) ?></strong>.
            </p>
            <div class="table-container">
                <table>
                    <tr>
                        <th>BatteryID</th>
                        <th>Battery</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Time/Date</th>
                    </tr>
                    <?php if (empty($historyRows)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No history found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historyRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['BatteryID']  ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Battery']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Type']       ?? '') ?></td>
                                <td><?= htmlspecialchars($row['FromLoc']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['ToLoc']      ?? '') ?></td>
                                <td><?= htmlspecialchars($row['LastUpdate'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Barcode Scanner Modal -->
<div id="scannerOverlay" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.8);
    z-index:9999;
    align-items:center;
    justify-content:center;
">
    <div style="background:#fff; padding:10px; border-radius:8px; max-width:400px; width:90%; text-align:center;">
        <h3 style="margin-top:0;">Scan Battery Barcode</h3>
        <video id="scannerVideo" style="width:100%; max-height:300px; background:#000;"></video>
        <p style="font-size:12px; color:#6b7280; margin-top:6px;">
            Align the barcode within the frame until it is detected.
        </p>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:8px;">
            <button type="button" onclick="switchCamera()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#2563eb;
                color:#fff;
            ">
                Switch Camera
            </button>
            <button type="button" onclick="closeScanner()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#4b5563;
                color:#fff;
            ">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ZXing and scanner script -->
<script src="https://unpkg.com/@zxing/library@latest"></script>
<script>
    let selectedInputId = null;
    let codeReader = null;
    let videoInputDevices = [];
    let currentDeviceIndex = 0;
    let currentStream = null;
    let audioCtx = null;

    function playBeep() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
            }
            const duration = 0.15; // seconds
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5-ish
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    }

    function stopCurrentStream() {
        const video = document.getElementById('scannerVideo');
        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }
        if (video) {
            video.srcObject = null;
        }
    }

    async function startDecodingWithCurrentDevice() {
        if (!videoInputDevices.length) return;

        const device = videoInputDevices[currentDeviceIndex];
        const deviceId = device.deviceId;

        stopCurrentStream();

        try {
            const video = document.getElementById('scannerVideo');

            // ZXing will call getUserMedia under the hood
            codeReader.decodeFromVideoDevice(deviceId, 'scannerVideo', (result, err) => {
                if (result) {
                    const input = document.getElementById(selectedInputId);
                    if (input) {
                        input.value = result.text;
                    }
                    playBeep();
                    closeScanner();
                }
                // err is usually "NotFoundException" while scanning – safe to ignore
            });

            // Save the stream from the video element once it starts
            setTimeout(() => {
                if (video && video.srcObject) {
                    currentStream = video.srcObject;
                }
            }, 500);

        } catch (e) {
            console.error('Error starting decode:', e);
            alert('Unable to start camera. Please check permissions.');
            closeScanner();
        }
    }

    function pickBackCameraIndex(devices) {
        // Prefer labels that look like back/rear/environment
        let idx = devices.findIndex(d =>
            /back|rear|environment/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Next, prefer anything that is NOT clearly front
        idx = devices.findIndex(d =>
            !/front|user/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Fallback to first
        return 0;
    }

    async function openScanner(inputId) {
        selectedInputId = inputId;
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'flex';

        if (!codeReader) {
            codeReader = new ZXing.BrowserMultiFormatReader();
        }

        try {
            // Get list of cameras
            videoInputDevices = await codeReader.listVideoInputDevices();
            if (!videoInputDevices.length) {
                alert('No camera found on this device.');
                closeScanner();
                return;
            }

            currentDeviceIndex = pickBackCameraIndex(videoInputDevices);
            await startDecodingWithCurrentDevice();
        } catch (e) {
            console.error(e);
            alert('Unable to access camera. Please check permissions.');
            closeScanner();
        }
    }

    function switchCamera() {
        if (!videoInputDevices.length || !codeReader) return;

        try {
            codeReader.reset();
        } catch (e) {
            console.warn(e);
        }
        stopCurrentStream();

        currentDeviceIndex = (currentDeviceIndex + 1) % videoInputDevices.length;
        startDecodingWithCurrentDevice();
    }

    function closeScanner() {
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'none';

        if (codeReader) {
            try {
                codeReader.reset();
            } catch (e) {
                console.warn(e);
            }
        }
        stopCurrentStream();
    }
</script>
</body>
</html>
If you want next, we can tweak the Stock Truck grid (e.g., color Need>0 red / Need<0 green) to make “needs” pop visually.

You said:
First on the audit log anytime there is a stocktype of BATTERY the quantity will always be 1 because it is a serialized battery. 
on the stock truck screen. Don't show the option to clear the truck until after the user has selected the truck and views the query results. Then just show the clear option. IF they select it, show the warning message and make them select a shop. Then make another button appear to process.

if they need a negative amount make red. 
ChatGPT said:

Thought for 1m 33s
Got it, that all makes sense:

✅ For AuditLog + StockType = 'BATTERY', Quantity will always be 1 on every insert.

✅ On Stock Truck:

User picks truck + runs query first.

Only after that do they see a “Clear Truck Inventory” option.

Clicking that shows a warning + shop dropdown + final Process button.

✅ In the Stock Truck table, any negative Need value is shown in red.

Here’s the full updated build with those changes wired in:

<?php
// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
    // Clear session data
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        // Session expired
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    } else {
        // Still active, refresh timer
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE (NO OUTPUT BEFORE THIS POINT) =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Please enter both AAA and Password.";
        } else {
            try {
                $dsnLogin = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
                $pdoLogin = new PDO($dsnLogin, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "
                    SELECT AAA, FirstName, LastName
                    FROM Employee
                    WHERE AAA = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA'];
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']     = true;
                    $_SESSION['empAAA']        = $empId;
                    $_SESSION['empFirst']      = $emp['FirstName'];
                    $_SESSION['empLast']       = $emp['LastName'];
                    $_SESSION['empName']       = $empName;
                    $_SESSION['last_activity'] = time(); // start timeout timer

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }

            } catch (Exception $e) {
                $error = "Login error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Browns Towing Battery Program Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2 style="text-align:center;">Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p style="color:orange;">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>AAA:</label><br>
            <input type="text" name="aaa"
                   style="width:100%; padding:8px; margin:8px 0;"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

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

// ===== WE HAVE A LOGGED IN USER =====
$empAAA  = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName = $_SESSION['empName'] ?? 'Tuna Marie';

// ===== CONNECT TO DB =====
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE =====
// views: menu | inventory | sell | transfer | scrap | stocktruck | history
$view = $_GET['view'] ?? 'menu';
$msg  = $_GET['msg']  ?? '';

// Predeclare vars
$invRows        = [];
$allLocations   = [];
$allBatteries   = [];

$sellError      = "";
$sellInfo       = null;

$transferError      = "";
$transferSuccess    = "";
$transferPreview    = null;
$transferToLoc      = "";
$transferBatteryId  = "";
$destRows           = [];

$scrapError      = "";
$scrapInfo       = null;

$historyRows     = [];

$soldTodayCount  = 0;

// Stock Truck
$stockTruckError          = "";
$stockTruckMessage        = "";
$stockTruckSelectedTruck  = "";
$stockTruckSelectedShop   = "";
$stockTruckRows           = [];
$stockTruckTruckList      = [];
$stockTruckShopList       = [];
$stockTruckDidShowQuery   = false;
$stockTruckShowConfirmClear = false;

// ===== INVENTORY SECTION =====
if ($view === 'inventory') {
    $selectedLocation = isset($_GET['loc']) ? trim($_GET['loc']) : '';
    $selectedBattery  = isset($_GET['bat']) ? trim($_GET['bat']) : '';

    // Dropdown data (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sqlAll = "
        SELECT 
            Inventory.Battery AS Battery,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
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

    // Aggregated query (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sql = "
        SELECT 
            Inventory.Battery AS Battery,
            COUNT(*) AS Quantity,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
    ";
    $params = [];

    if ($selectedLocation !== '') {
        $sql .= " AND Inventory.Location = :loc";
        $params[':loc'] = $selectedLocation;
    }
    if ($selectedBattery !== '') {
        $sql .= " AND Inventory.Battery = :bat";
        $params[':bat'] = $selectedBattery;
    }

    $sql .= "
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== SELL BATTERY SECTION =====
if ($view === 'sell') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $sellError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm sale
        elseif (isset($_POST['confirm_sell'])) {
            $bid = trim($_POST['battery_id'] ?? '');

            if ($bid === '') {
                $sellError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $fromLoc = $row['Location'];
                        $now     = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory 
                            SET Location = 'SOLD' 
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                            VALUES
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':batteryId'  => $row['BatteryID'],
                            ':battery'    => $row['Battery'],
                            ':dateCode'   => $row['DateCode'],
                            ':lastUpdate' => $now,
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

// ===== TRANSFER BATTERY SECTION =====
if ($view === 'transfer') {
    // Build combined destination list: shops + trucks
    $stmtDest = $pdo->query("
        SELECT Location AS ToLoc, 'SHOP' AS Type
        FROM Location
        UNION ALL
        SELECT Truck AS ToLoc, 'TRUCK' AS Type
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
    ");
    $rowsDest = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($rowsDest as $r) {
        $loc  = trim($r['ToLoc'] ?? '');
        $type = $r['Type'] ?? 'TRUCK';
        if ($loc === '') continue;
        if (!isset($seen[$loc])) {
            $seen[$loc] = $type;
        }
    }

    foreach ($seen as $loc => $type) {
        $destRows[] = [
            'ToLoc' => $loc,
            'Type'  => $type
        ];
    }

    // Sort: shops first, then trucks; each group descending by name
    usort($destRows, function($a, $b) {
        if ($a['Type'] !== $b['Type']) {
            return ($a['Type'] === 'SHOP') ? -1 : 1;
        }
        return strcasecmp($b['ToLoc'], $a['ToLoc']);
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Preview transfer
        if (isset($_POST['preview_transfer'])) {
            $bid        = trim($_POST['battery_id'] ?? '');
            $transferTo = trim($_POST['to_loc'] ?? '');
            $transferToLoc     = $transferTo;
            $transferBatteryId = $bid;

            if ($bid === '') {
                $transferError = "Please enter a BatteryID.";
            } elseif ($transferTo === '') {
                $transferError = "Please select a destination.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED.";
                } else {
                    $fromLoc = $row['Location'];

                    if ($fromLoc === $transferTo) {
                        $transferError = "BatteryID already at location.";
                    } else {
                        $transferPreview = [
                            'BatteryID' => $row['BatteryID'],
                            'Battery'   => $row['Battery'],
                            'DateCode'  => $row['DateCode'],
                            'FromLoc'   => $fromLoc,
                            'ToLoc'     => $transferTo,
                        ];
                    }
                }
            }
        }

        // Step 2: Confirm transfer
        elseif (isset($_POST['confirm_transfer'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $fromLoc   = trim($_POST['from_loc'] ?? '');
            $toLoc     = trim($_POST['to_loc'] ?? '');
            $battery   = trim($_POST['battery'] ?? '');
            $dateCode  = trim($_POST['date_code'] ?? '');

            // keep UI selections
            $transferToLoc     = $toLoc;
            $transferBatteryId = $bid;

            if ($bid === '' || $fromLoc === '' || $toLoc === '') {
                $transferError = "Missing transfer data. Please try again.";
            } elseif ($fromLoc === $toLoc) {
                $transferError = "BatteryID already at location.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $check = $pdo->prepare("
                        SELECT Inventory.Location
                        FROM Inventory
                        WHERE BatteryID = :bid
                          AND Location NOT IN ('SOLD','SCRAPPED')
                          AND StockType = 'BATTERY'
                    ");
                    $check->execute([':bid' => $bid]);
                    $current = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$current || $current['Location'] !== $fromLoc) {
                        $pdo->rollBack();
                        $transferError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                    } else {
                        $now = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :toLoc
                            WHERE BatteryID = :bid
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([
                            ':toLoc' => $toLoc,
                            ':bid'   => $bid
                        ]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':toLoc'      => $toLoc,
                            ':batteryId'  => $bid,
                            ':battery'    => $battery,
                            ':dateCode'   => $dateCode,
                            ':lastUpdate' => $now,
                        ]);

                        $pdo->commit();

                        // Stay on Transfer page and show success
                        $transferSuccess   = "Battery was successfully transferred and logged.";
                        $transferPreview   = null;
                        $transferBatteryId = ""; // clear BatteryID prompt after successful transfer
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $transferError = "Error transferring battery: " . $e->getMessage();
                }
            }
        }
    }
}

// ===== SCRAP BATTERY SECTION =====
if ($view === 'scrap') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup battery
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $scrapError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $scrapInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$scrapInfo) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm scrap
        elseif (isset($_POST['confirm_scrap'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $reasonRaw = $_POST['reason'] ?? '';

            // We’ll requery battery info for safety
            if ($bid === '') {
                $scrapError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    // Validate reason
                    $reasonTrim  = trim($reasonRaw);
                    if ($reasonTrim === '') {
                        $scrapError = "Reason is required to scrap a battery.";
                        $scrapInfo  = $row; // keep display
                    } else {
                        // Clean reason: remove single quotes & limit to 255 chars
                        $reasonClean = str_replace("'", "", $reasonTrim);
                        $reasonClean = mb_substr($reasonClean, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $fromLoc = $row['Location'];
                            $now     = date('Y-m-d H:i:s');

                            // Update Inventory to SCRAPPED
                            $update = $pdo->prepare("
                                UPDATE Inventory 
                                SET Location = 'SCRAPPED'
                                WHERE BatteryID = :bid
                                  AND Location NOT IN ('SOLD','SCRAPPED')
                                  AND StockType = 'BATTERY'
                            ");
                            $update->execute([':bid' => $bid]);

                            // Insert AuditLog (ToLoc = SCRAPPED, Reason = user text)
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                                VALUES
                                    (:empId, :empName, :fromLoc, 'SCRAPPED', :batteryId, 'Scrap', '', :battery, :dateCode, :reason, 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':batteryId'  => $row['BatteryID'],
                                ':battery'    => $row['Battery'],
                                ':dateCode'   => $row['DateCode'],
                                ':reason'     => $reasonClean,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=scrapped");
                            exit;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $scrapError = "Error scrapping battery: " . $e->getMessage();
                            $scrapInfo  = $row;
                        }
                    }
                }
            }
        }
    }
}

// ===== STOCK TRUCK SECTION =====
if ($view === 'stocktruck') {
    // Load truck list (from Trucks.Truck)
    $stmtTrucks = $pdo->query("
        SELECT Truck
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
        ORDER BY Truck
    ");
    $stockTruckTruckList = $stmtTrucks->fetchAll(PDO::FETCH_COLUMN);

    // Load shop list (from Location.Location)
    $stmtShops = $pdo->query("
        SELECT Location
        FROM Location
        WHERE Location IS NOT NULL AND Location <> ''
        ORDER BY Location
    ");
    $stockTruckShopList = $stmtShops->fetchAll(PDO::FETCH_COLUMN);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $stockTruckSelectedTruck = trim($_POST['truck'] ?? '');
        $stockTruckSelectedShop  = trim($_POST['shop_loc'] ?? '');

        // Helper function to run the stock query
        $runStockQuery = function($pdo, $truck) {
            $sqlTruck = "
                SELECT 
                    Battery,
                    Current,
                    Min,
                    Need
                FROM (
                    SELECT 
                        bm.Location,
                        bm.Battery,
                        COUNT(inv.BatteryID) AS Current,
                        bm.Minimum AS Min,
                        (COUNT(inv.BatteryID) - bm.Minimum) * -1 AS Need
                    FROM BatteryMinimum bm
                    LEFT JOIN Inventory inv
                        ON inv.Location = bm.Location
                        AND inv.Battery = bm.Battery
                        AND inv.StockType = 'BATTERY'
                    WHERE bm.Location = :truck
                    GROUP BY bm.Location, bm.Battery, bm.Minimum

                    UNION ALL

                    SELECT
                        inv.Location,
                        inv.Battery,
                        COUNT(inv.BatteryID) AS Current,
                        0 AS Min,
                        COUNT(inv.BatteryID) * -1 AS Need
                    FROM Inventory inv
                    LEFT JOIN BatteryMinimum bm
                        ON bm.Location = inv.Location
                        AND bm.Battery = inv.Battery
                    WHERE inv.Location = :truck
                      AND inv.StockType = 'BATTERY'
                      AND bm.Battery IS NULL
                    GROUP BY inv.Location, inv.Battery
                ) AS x
                WHERE Need <> 0
                ORDER BY Battery
            ";
            $stmtTruck = $pdo->prepare($sqlTruck);
            $stmtTruck->execute([':truck' => $truck]);
            return $stmtTruck->fetchAll(PDO::FETCH_ASSOC);
        };

        // Show truck stock
        if (isset($_POST['show_truck'])) {
            $stockTruckShowConfirmClear = false;
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck.";
            } else {
                $stockTruckRows       = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery = true;
            }
        }

        // Step 1: User clicked "Clear Truck Inventory" (show warning + shop)
        elseif (isset($_POST['start_clear'])) {
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck before clearing.";
            } else {
                $stockTruckRows         = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery = true;
                $stockTruckShowConfirmClear = true;
            }
        }

        // Step 2: Process the clear after warning + shop select
        elseif (isset($_POST['process_clear'])) {
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck before clearing.";
                $stockTruckShowConfirmClear = true;
            } elseif ($stockTruckSelectedShop === '') {
                $stockTruckError = "Please select a shop to move inventory to.";
                $stockTruckShowConfirmClear = true;
            } else {
                try {
                    $pdo->beginTransaction();

                    // Select all battery inventory on that truck
                    $selInv = $pdo->prepare("
                        SELECT BatteryID, Battery, DateCode
                        FROM Inventory
                        WHERE Location = :truck
                          AND StockType = 'BATTERY'
                    ");
                    $selInv->execute([':truck' => $stockTruckSelectedTruck]);
                    $truckInvRows = $selInv->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($truckInvRows)) {
                        $pdo->rollBack();
                        $stockTruckError = "No battery inventory found on truck " . $stockTruckSelectedTruck . ".";
                        $stockTruckShowConfirmClear = true;
                    } else {
                        // Move inventory to selected shop
                        $updInv = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :shop
                            WHERE Location = :truck
                              AND StockType = 'BATTERY'
                        ");
                        $updInv->execute([
                            ':shop'  => $stockTruckSelectedShop,
                            ':truck' => $stockTruckSelectedTruck
                        ]);

                        // Insert one AuditLog record per battery
                        $insAudit = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'ClearTruck', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                        ");

                        $now = date('Y-m-d H:i:s');

                        foreach ($truckInvRows as $r) {
                            $insAudit->execute([
                                ':empId'     => $empAAA,
                                ':empName'   => $empName,
                                ':fromLoc'   => $stockTruckSelectedTruck,
                                ':toLoc'     => $stockTruckSelectedShop,
                                ':batteryId' => $r['BatteryID'],
                                ':battery'   => $r['Battery'],
                                ':dateCode'  => $r['DateCode'],
                                ':lastUpdate'=> $now,
                            ]);
                        }

                        $pdo->commit();

                        $stockTruckMessage = "Truck " . $stockTruckSelectedTruck .
                            " inventory successfully moved to " . $stockTruckSelectedShop . ".";
                        $stockTruckRows           = [];
                        $stockTruckShowConfirmClear = false;
                        $stockTruckDidShowQuery   = true;
                    }

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $stockTruckError = "Error clearing truck: " . $e->getMessage();
                    $stockTruckShowConfirmClear = true;
                }
            }

            // Re-run query to show updated view (may be empty after clear)
            if ($stockTruckSelectedTruck !== '') {
                $stockTruckRows         = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery = true;
            }
        }
    }
}

// ===== HISTORY SECTION =====
if ($view === 'history') {
    try {
        $stmtHist = $pdo->prepare("
            SELECT 
                BatteryID,
                Battery,
                Type,
                ToLoc,
                FromLoc,
                LastUpdate
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND StockType = 'BATTERY'
              AND Type NOT IN ('Log On', 'Receive')
            ORDER BY LastUpdate DESC
            LIMIT 25
        ");
        $stmtHist->execute([':empId' => $empAAA]);
        $historyRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $historyRows = [];
    }
}

// ===== SOLD TODAY COUNT (MENU ONLY, BASED ON EST/EDT) =====
if ($view === 'menu') {
    try {
        // Local "today" in EST/EDT
        $startToday = date('Y-m-d 00:00:00');
        $endToday   = date('Y-m-d 23:59:59');

        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND LastUpdate >= :startToday
              AND LastUpdate <= :endToday
        ");
        $stmtSold->execute([
            ':empId'      => $empAAA,
            ':startToday' => $startToday,
            ':endToday'   => $endToday,
        ]);
        $soldTodayCount = (int)$stmtSold->fetchColumn();
    } catch (Exception $e) {
        $soldTodayCount = 0; // fail quietly
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
                grid-template-columns: repeat(4, 1fr);
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
        select, input[type="text"], textarea {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        textarea {
            min-height: 80px;
            resize: vertical;
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
        .mt-6  { margin-top: 6px; }
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php elseif ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php elseif ($msg === 'scrapped'): ?>
        <div class="msg msg-success">
            Battery was successfully scrapped and logged.
        </div>
    <?php endif; ?>

    <?php if ($view === 'menu'): ?>

        <h2>Main Menu</h2>
        <div class="menu-grid">
            <a class="btn" href="?view=inventory">Inventory</a>
            <a class="btn" href="?view=sell">Sell Battery</a>
            <a class="btn" href="?view=stocktruck">Stock Truck</a>
            <a class="btn" href="?view=transfer">Transfer Battery</a>
            <a class="btn" href="?view=scrap">Scrap Battery</a>
            <a class="btn" href="?view=history">History</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong> (<?= htmlspecialchars($empAAA) ?>).<br>
                Use the buttons above to manage batteries.
            </p>

            <?php if ($soldTodayCount > 0): ?>
                <p class="text-center" style="font-size:13px; color:#166534; margin-top:6px;">
                    You have sold <strong><?= $soldTodayCount ?></strong>
                    battery<?= ($soldTodayCount === 1 ? '' : 'ies') ?> so far today.
                </p>
            <?php endif; ?>

            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary" style="max-width:200px; display:inline-block;">
                    Logout
                </a>
            </div>
        </div>

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

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="sell_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('sell_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not previously <strong>SOLD</strong> or <strong>SCRAPPED</strong> are eligible.
            </p>
        </div>

        <!-- Step 2: Confirm Sale -->
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

        <?php if (!empty($transferSuccess)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($transferSuccess) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: BatteryID + Destination -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="transfer_battery_id"
                           style="flex:1;"
                           value="<?= htmlspecialchars($transferBatteryId ?? '') ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('transfer_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <label class="label-block mt-10">Transfer To</label>
                <select name="to_loc">
                    <option value="">Select Destination</option>
                    <?php foreach ($destRows as $d): ?>
                        <?php
                        $label = $d['ToLoc'] . ' (' . $d['Type'] . ')';
                        $val   = $d['ToLoc'];
                        ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                            <?= ($val === ($transferToLoc ?? '')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="preview_transfer" class="btn mt-10">
                    Preview Transfer
                </button>
            </form>

            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be transferred.
            </p>
        </div>

        <!-- Step 2: Preview + Confirm -->
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

    <?php elseif ($view === 'scrap'): ?>

        <h2>Scrap a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($scrapError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($scrapError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="scrap_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('scrap_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be scrapped.
            </p>
        </div>

        <!-- Step 2: Confirm Scrap + Reason -->
        <?php if ($scrapInfo): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Scrap</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($scrapInfo['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($scrapInfo['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($scrapInfo['DateCode']) ?></p>
                <p><strong>Current Location:</strong> <?= htmlspecialchars($scrapInfo['Location']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($scrapInfo['BatteryID']) ?>">

                    <label class="label-block">Reason for Scrap (required, max 255 chars)</label>
                    <textarea name="reason" maxlength="255"
                              placeholder="Describe why this battery is being scrapped."><?= isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : '' ?></textarea>

                    <button type="submit" name="confirm_scrap" class="btn mt-10">
                        Scrap This Battery
                    </button>
                </form>

                <p class="mt-6 small-note">
                    Reason text will be cleaned (for example, single quotes removed) before being stored in the audit log.
                </p>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'stocktruck'): ?>

        <h2>Stock Truck</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($stockTruckError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($stockTruckError) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($stockTruckMessage)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($stockTruckMessage) ?>
            </div>
        <?php endif; ?>

        <!-- Select Truck & Show Stock -->
        <div class="card">
            <form method="post">
                <label class="label-block">Truck</label>
                <select name="truck">
                    <option value="">Select Truck</option>
                    <?php foreach ($stockTruckTruckList as $truck): ?>
                        <option value="<?= htmlspecialchars($truck) ?>"
                            <?= ($truck === $stockTruckSelectedTruck ? 'selected' : '') ?>>
                            <?= htmlspecialchars($truck) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="show_truck" class="btn mt-10">
                    Show Truck Stock
                </button>
            </form>
            <p class="mt-6 small-note">
                Shows batteries where current on-truck quantity does not match the defined minimums.
            </p>
        </div>

        <!-- Stock Results -->
        <?php if ($stockTruckDidShowQuery && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Truck Stock for <?= htmlspecialchars($stockTruckSelectedTruck) ?></h3>
                <div class="table-container">
                    <table>
                        <tr>
                            <th>Battery</th>
                            <th>Current</th>
                            <th>Min</th>
                            <th>Need</th>
                        </tr>
                        <?php if (empty($stockTruckRows)): ?>
                            <tr>
                                <td colspan="4" class="text-center">No variances found for this truck.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stockTruckRows as $row): ?>
                                <?php $needVal = (int)($row['Need'] ?? 0); ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['Battery'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['Current'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['Min'] ?? '') ?></td>
                                    <td style="<?= $needVal < 0 ? 'color:#b91c1c;font-weight:bold;' : '' ?>">
                                        <?= htmlspecialchars($row['Need'] ?? '') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Step 1: Show Clear Truck option ONLY after query has been run -->
        <?php if ($stockTruckDidShowQuery && !$stockTruckShowConfirmClear && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Clear Truck Inventory</h3>
                <form method="post">
                    <input type="hidden" name="truck" value="<?= htmlspecialchars($stockTruckSelectedTruck) ?>">
                    <button type="submit" name="start_clear" class="btn mt-10">
                        Clear This Truck
                    </button>
                </form>
                <p class="mt-6 small-note">
                    Use this if you want to move <strong>all BATTERY inventory</strong> from this truck to a shop.
                </p>
            </div>
        <?php endif; ?>

        <!-- Step 2: Warning + Shop selection + Process button -->
        <?php if ($stockTruckShowConfirmClear && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Clear Truck</h3>

                <p class="small-note" style="color:#b91c1c; font-weight:bold;">
                    Warning: Continuing will move <strong>all BATTERY inventory</strong> from truck
                    <strong><?= htmlspecialchars($stockTruckSelectedTruck) ?></strong>
                    to the selected shop location. This action cannot be undone.
                </p>

                <form method="post">
                    <input type="hidden" name="truck" value="<?= htmlspecialchars($stockTruckSelectedTruck) ?>">

                    <label class="label-block mt-10">Move inventory to Shop</label>
                    <select name="shop_loc">
                        <option value="">Select Shop Location</option>
                        <?php foreach ($stockTruckShopList as $shop): ?>
                            <option value="<?= htmlspecialchars($shop) ?>"
                                <?= ($shop === $stockTruckSelectedShop ? 'selected' : '') ?>>
                                <?= htmlspecialchars($shop) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" name="process_clear" class="btn mt-10">
                        Process Clear Truck
                    </button>
                </form>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'history'): ?>

        <h2>History</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="small-note">
                Showing the most recent 25 events for <strong><?= htmlspecialchars($empName) ?></strong>.
            </p>
            <div class="table-container">
                <table>
                    <tr>
                        <th>BatteryID</th>
                        <th>Battery</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Time/Date</th>
                    </tr>
                    <?php if (empty($historyRows)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No history found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historyRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['BatteryID']  ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Battery']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Type']       ?? '') ?></td>
                                <td><?= htmlspecialchars($row['FromLoc']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['ToLoc']      ?? '') ?></td>
                                <td><?= htmlspecialchars($row['LastUpdate'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Barcode Scanner Modal -->
<div id="scannerOverlay" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.8);
    z-index:9999;
    align-items:center;
    justify-content:center;
">
    <div style="background:#fff; padding:10px; border-radius:8px; max-width:400px; width:90%; text-align:center;">
        <h3 style="margin-top:0;">Scan Battery Barcode</h3>
        <video id="scannerVideo" style="width:100%; max-height:300px; background:#000;"></video>
        <p style="font-size:12px; color:#6b7280; margin-top:6px;">
            Align the barcode within the frame until it is detected.
        </p>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:8px;">
            <button type="button" onclick="switchCamera()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#2563eb;
                color:#fff;
            ">
                Switch Camera
            </button>
            <button type="button" onclick="closeScanner()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#4b5563;
                color:#fff;
            ">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ZXing and scanner script -->
<script src="https://unpkg.com/@zxing/library@latest"></script>
<script>
    let selectedInputId = null;
    let codeReader = null;
    let videoInputDevices = [];
    let currentDeviceIndex = 0;
    let currentStream = null;
    let audioCtx = null;

    function playBeep() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
            }
            const duration = 0.15; // seconds
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5-ish
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    }

    function stopCurrentStream() {
        const video = document.getElementById('scannerVideo');
        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }
        if (video) {
            video.srcObject = null;
        }
    }

    async function startDecodingWithCurrentDevice() {
        if (!videoInputDevices.length) return;

        const device = videoInputDevices[currentDeviceIndex];
        const deviceId = device.deviceId;

        stopCurrentStream();

        try {
            const video = document.getElementById('scannerVideo');

            // ZXing will call getUserMedia under the hood
            codeReader.decodeFromVideoDevice(deviceId, 'scannerVideo', (result, err) => {
                if (result) {
                    const input = document.getElementById(selectedInputId);
                    if (input) {
                        input.value = result.text;
                    }
                    playBeep();
                    closeScanner();
                }
                // err is usually "NotFoundException" while scanning – safe to ignore
            });

            // Save the stream from the video element once it starts
            setTimeout(() => {
                if (video && video.srcObject) {
                    currentStream = video.srcObject;
                }
            }, 500);

        } catch (e) {
            console.error('Error starting decode:', e);
            alert('Unable to start camera. Please check permissions.');
            closeScanner();
        }
    }

    function pickBackCameraIndex(devices) {
        // Prefer labels that look like back/rear/environment
        let idx = devices.findIndex(d =>
            /back|rear|environment/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Next, prefer anything that is NOT clearly front
        idx = devices.findIndex(d =>
            !/front|user/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Fallback to first
        return 0;
    }

    async function openScanner(inputId) {
        selectedInputId = inputId;
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'flex';

        if (!codeReader) {
            codeReader = new ZXing.BrowserMultiFormatReader();
        }

        try {
            // Get list of cameras
            videoInputDevices = await codeReader.listVideoInputDevices();
            if (!videoInputDevices.length) {
                alert('No camera found on this device.');
                closeScanner();
                return;
            }

            currentDeviceIndex = pickBackCameraIndex(videoInputDevices);
            await startDecodingWithCurrentDevice();
        } catch (e) {
            console.error(e);
            alert('Unable to access camera. Please check permissions.');
            closeScanner();
        }
    }

    function switchCamera() {
        if (!videoInputDevices.length || !codeReader) return;

        try {
            codeReader.reset();
        } catch (e) {
            console.warn(e);
        }
        stopCurrentStream();

        currentDeviceIndex = (currentDeviceIndex + 1) % videoInputDevices.length;
        startDecodingWithCurrentDevice();
    }

    function closeScanner() {
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'none';

        if (codeReader) {
            try {
                codeReader.reset();
            } catch (e) {
                console.warn(e);
            }
        }
        stopCurrentStream();
    }
</script>
</body>
</html>
If you want, next pass we can add a tiny legend under the Stock Truck grid like: “Negative Need = too many on truck (shown in red)” so techs don’t have to remember the sign convention.

You said:
Yes please add that wording
Also on the transfer battery, after we submit clear the BatteryID but keep the Transfer to. If they are transferring multiple its going to be to the same truck. 

On the stock truck, below the query results and above the clear truck option. Give the user the ability to transfer a battery to that truck. Use the same exact logic in the transfer battery option, but use the current truck as the location to. Once submitted rerun the query. Allow the same camera option used in the transfer battery as well
ChatGPT said:

Thought for 49s
Here’s the updated full build with:

Legend under Stock Truck grid explaining negative Need in red.

Transfer page already clears BatteryID but keeps “Transfer To” after a successful transfer (I left that behavior in and made sure it’s consistent).

On Stock Truck:

After running the truck query, users see:

A “Transfer Battery to This Truck” form with scan button.

Then the Clear Truck flow:

First a “Clear This Truck” button.

Then (after click) the warning + shop dropdown + “Process Clear Truck” button.

All using the same barcode scanner logic (openScanner) as the other screens.

<?php
// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
    // Clear session data
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        // Session expired
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    } else {
        // Still active, refresh timer
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE (NO OUTPUT BEFORE THIS POINT) =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Please enter both AAA and Password.";
        } else {
            try {
                $dsnLogin = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
                $pdoLogin = new PDO($dsnLogin, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "
                    SELECT AAA, FirstName, LastName
                    FROM Employee
                    WHERE AAA = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA'];
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']     = true;
                    $_SESSION['empAAA']        = $empId;
                    $_SESSION['empFirst']      = $emp['FirstName'];
                    $_SESSION['empLast']       = $emp['LastName'];
                    $_SESSION['empName']       = $empName;
                    $_SESSION['last_activity'] = time(); // start timeout timer

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }

            } catch (Exception $e) {
                $error = "Login error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Browns Towing Battery Program Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2 style="text-align:center;">Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p style="color:orange;">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>AAA:</label><br>
            <input type="text" name="aaa"
                   style="width:100%; padding:8px; margin:8px 0;"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

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

// ===== WE HAVE A LOGGED IN USER =====
$empAAA  = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName = $_SESSION['empName'] ?? 'Tuna Marie';

// ===== CONNECT TO DB =====
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE =====
// views: menu | inventory | sell | transfer | scrap | stocktruck | history
$view = $_GET['view'] ?? 'menu';
$msg  = $_GET['msg']  ?? '';

// Predeclare vars
$invRows        = [];
$allLocations   = [];
$allBatteries   = [];

$sellError      = "";
$sellInfo       = null;

$transferError      = "";
$transferSuccess    = "";
$transferPreview    = null;
$transferToLoc      = "";
$transferBatteryId  = "";
$destRows           = [];

$scrapError      = "";
$scrapInfo       = null;

$historyRows     = [];

$soldTodayCount  = 0;

// Stock Truck
$stockTruckError             = "";
$stockTruckMessage           = "";
$stockTruckSelectedTruck     = "";
$stockTruckSelectedShop      = "";
$stockTruckRows              = [];
$stockTruckTruckList         = [];
$stockTruckShopList          = [];
$stockTruckDidShowQuery      = false;
$stockTruckShowConfirmClear  = false;
$stockTruckTransferBatteryId = "";

// ===== INVENTORY SECTION =====
if ($view === 'inventory') {
    $selectedLocation = isset($_GET['loc']) ? trim($_GET['loc']) : '';
    $selectedBattery  = isset($_GET['bat']) ? trim($_GET['bat']) : '';

    // Dropdown data (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sqlAll = "
        SELECT 
            Inventory.Battery AS Battery,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
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

    // Aggregated query (NO SOLD / SCRAPPED) - INVENTORY ONLY, BATTERY STOCKTYPE
    $sql = "
        SELECT 
            Inventory.Battery AS Battery,
            COUNT(*) AS Quantity,
            Inventory.Location AS Location
        FROM Inventory
        WHERE Inventory.Location NOT IN ('SOLD','SCRAPPED')
          AND Inventory.StockType = 'BATTERY'
    ";
    $params = [];

    if ($selectedLocation !== '') {
        $sql .= " AND Inventory.Location = :loc";
        $params[':loc'] = $selectedLocation;
    }
    if ($selectedBattery !== '') {
        $sql .= " AND Inventory.Battery = :bat";
        $params[':bat'] = $selectedBattery;
    }

    $sql .= "
        GROUP BY Inventory.Battery, Inventory.Location
        ORDER BY Inventory.Battery, Inventory.Location
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== SELL BATTERY SECTION =====
if ($view === 'sell') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $sellError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm sale
        elseif (isset($_POST['confirm_sell'])) {
            $bid = trim($_POST['battery_id'] ?? '');

            if ($bid === '') {
                $sellError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $fromLoc = $row['Location'];
                        $now     = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory 
                            SET Location = 'SOLD' 
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                            VALUES
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':batteryId'  => $row['BatteryID'],
                            ':battery'    => $row['Battery'],
                            ':dateCode'   => $row['DateCode'],
                            ':lastUpdate' => $now,
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

// ===== TRANSFER BATTERY SECTION =====
if ($view === 'transfer') {
    // Build combined destination list: shops + trucks
    $stmtDest = $pdo->query("
        SELECT Location AS ToLoc, 'SHOP' AS Type
        FROM Location
        UNION ALL
        SELECT Truck AS ToLoc, 'TRUCK' AS Type
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
    ");
    $rowsDest = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($rowsDest as $r) {
        $loc  = trim($r['ToLoc'] ?? '');
        $type = $r['Type'] ?? 'TRUCK';
        if ($loc === '') continue;
        if (!isset($seen[$loc])) {
            $seen[$loc] = $type;
        }
    }

    foreach ($seen as $loc => $type) {
        $destRows[] = [
            'ToLoc' => $loc,
            'Type'  => $type
        ];
    }

    // Sort: shops first, then trucks; each group ascending by name
    usort($destRows, function($a, $b) {
        if ($a['Type'] !== $b['Type']) {
            return ($a['Type'] === 'SHOP') ? -1 : 1;
        }
        return strcasecmp($a['ToLoc'], $b['ToLoc']);
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Preview transfer
        if (isset($_POST['preview_transfer'])) {
            $bid        = trim($_POST['battery_id'] ?? '');
            $transferTo = trim($_POST['to_loc'] ?? '');
            $transferToLoc     = $transferTo;
            $transferBatteryId = $bid;

            if ($bid === '') {
                $transferError = "Please enter a BatteryID.";
            } elseif ($transferTo === '') {
                $transferError = "Please select a destination.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED.";
                } else {
                    $fromLoc = $row['Location'];

                    if ($fromLoc === $transferTo) {
                        $transferError = "BatteryID already at location.";
                    } else {
                        $transferPreview = [
                            'BatteryID' => $row['BatteryID'],
                            'Battery'   => $row['Battery'],
                            'DateCode'  => $row['DateCode'],
                            'FromLoc'   => $fromLoc,
                            'ToLoc'     => $transferTo,
                        ];
                    }
                }
            }
        }

        // Step 2: Confirm transfer
        elseif (isset($_POST['confirm_transfer'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $fromLoc   = trim($_POST['from_loc'] ?? '');
            $toLoc     = trim($_POST['to_loc'] ?? '');
            $battery   = trim($_POST['battery'] ?? '');
            $dateCode  = trim($_POST['date_code'] ?? '');

            // keep UI selections
            $transferToLoc     = $toLoc;
            $transferBatteryId = $bid;

            if ($bid === '' || $fromLoc === '' || $toLoc === '') {
                $transferError = "Missing transfer data. Please try again.";
            } elseif ($fromLoc === $toLoc) {
                $transferError = "BatteryID already at location.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $check = $pdo->prepare("
                        SELECT Inventory.Location
                        FROM Inventory
                        WHERE BatteryID = :bid
                          AND Location NOT IN ('SOLD','SCRAPPED')
                          AND StockType = 'BATTERY'
                    ");
                    $check->execute([':bid' => $bid]);
                    $current = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$current || $current['Location'] !== $fromLoc) {
                        $pdo->rollBack();
                        $transferError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                    } else {
                        $now = date('Y-m-d H:i:s');

                        // Update Inventory
                        $update = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :toLoc
                            WHERE BatteryID = :bid
                              AND StockType = 'BATTERY'
                        ");
                        $update->execute([
                            ':toLoc' => $toLoc,
                            ':bid'   => $bid
                        ]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':toLoc'      => $toLoc,
                            ':batteryId'  => $bid,
                            ':battery'    => $battery,
                            ':dateCode'   => $dateCode,
                            ':lastUpdate' => $now,
                        ]);

                        $pdo->commit();

                        // Stay on Transfer page and show success
                        $transferSuccess   = "Battery was successfully transferred and logged.";
                        $transferPreview   = null;
                        $transferBatteryId = ""; // clear BatteryID prompt after successful transfer
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $transferError = "Error transferring battery: " . $e->getMessage();
                }
            }
        }
    }
}

// ===== SCRAP BATTERY SECTION =====
if ($view === 'scrap') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup battery
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $scrapError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $scrapInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$scrapInfo) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm scrap
        elseif (isset($_POST['confirm_scrap'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $reasonRaw = $_POST['reason'] ?? '';

            // We’ll requery battery info for safety
            if ($bid === '') {
                $scrapError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Inventory.BatteryID,
                        Inventory.Battery,
                        Inventory.DateCode,
                        Inventory.Location
                    FROM Inventory
                    WHERE Inventory.BatteryID = :bid
                      AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                      AND Inventory.StockType = 'BATTERY'
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    // Validate reason
                    $reasonTrim  = trim($reasonRaw);
                    if ($reasonTrim === '') {
                        $scrapError = "Reason is required to scrap a battery.";
                        $scrapInfo  = $row; // keep display
                    } else {
                        // Clean reason: remove single quotes & limit to 255 chars
                        $reasonClean = str_replace("'", "", $reasonTrim);
                        $reasonClean = mb_substr($reasonClean, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $fromLoc = $row['Location'];
                            $now     = date('Y-m-d H:i:s');

                            // Update Inventory to SCRAPPED
                            $update = $pdo->prepare("
                                UPDATE Inventory 
                                SET Location = 'SCRAPPED'
                                WHERE BatteryID = :bid
                                  AND Location NOT IN ('SOLD','SCRAPPED')
                                  AND StockType = 'BATTERY'
                            ");
                            $update->execute([':bid' => $bid]);

                            // Insert AuditLog (ToLoc = SCRAPPED, Reason = user text)
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                                VALUES
                                    (:empId, :empName, :fromLoc, 'SCRAPPED', :batteryId, 'Scrap', '', :battery, :dateCode, :reason, 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':batteryId'  => $row['BatteryID'],
                                ':battery'    => $row['Battery'],
                                ':dateCode'   => $row['DateCode'],
                                ':reason'     => $reasonClean,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=scrapped");
                            exit;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $scrapError = "Error scrapping battery: " . $e->getMessage();
                            $scrapInfo  = $row;
                        }
                    }
                }
            }
        }
    }
}

// ===== STOCK TRUCK SECTION =====
if ($view === 'stocktruck') {
    // Load truck list (from Trucks.Truck)
    $stmtTrucks = $pdo->query("
        SELECT Truck
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
        ORDER BY Truck
    ");
    $stockTruckTruckList = $stmtTrucks->fetchAll(PDO::FETCH_COLUMN);

    // Load shop list (from Location.Location)
    $stmtShops = $pdo->query("
        SELECT Location
        FROM Location
        WHERE Location IS NOT NULL AND Location <> ''
        ORDER BY Location
    ");
    $stockTruckShopList = $stmtShops->fetchAll(PDO::FETCH_COLUMN);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $stockTruckSelectedTruck     = trim($_POST['truck'] ?? '');
        $stockTruckSelectedShop      = trim($_POST['shop_loc'] ?? '');
        $stockTruckTransferBatteryId = trim($_POST['battery_id'] ?? $stockTruckTransferBatteryId);

        // Helper function to run the stock query
        $runStockQuery = function($pdo, $truck) {
            $sqlTruck = "
                SELECT 
                    Battery,
                    Current,
                    Min,
                    Need
                FROM (
                    SELECT 
                        bm.Location,
                        bm.Battery,
                        COUNT(inv.BatteryID) AS Current,
                        bm.Minimum AS Min,
                        (COUNT(inv.BatteryID) - bm.Minimum) * -1 AS Need
                    FROM BatteryMinimum bm
                    LEFT JOIN Inventory inv
                        ON inv.Location = bm.Location
                        AND inv.Battery = bm.Battery
                        AND inv.StockType = 'BATTERY'
                    WHERE bm.Location = :truck
                    GROUP BY bm.Location, bm.Battery, bm.Minimum

                    UNION ALL

                    SELECT
                        inv.Location,
                        inv.Battery,
                        COUNT(inv.BatteryID) AS Current,
                        0 AS Min,
                        COUNT(inv.BatteryID) * -1 AS Need
                    FROM Inventory inv
                    LEFT JOIN BatteryMinimum bm
                        ON bm.Location = inv.Location
                        AND bm.Battery = inv.Battery
                    WHERE inv.Location = :truck
                      AND inv.StockType = 'BATTERY'
                      AND bm.Battery IS NULL
                    GROUP BY inv.Location, inv.Battery
                ) AS x
                WHERE Need <> 0
                ORDER BY Battery
            ";
            $stmtTruck = $pdo->prepare($sqlTruck);
            $stmtTruck->execute([':truck' => $truck]);
            return $stmtTruck->fetchAll(PDO::FETCH_ASSOC);
        };

        // Show truck stock
        if (isset($_POST['show_truck'])) {
            $stockTruckShowConfirmClear = false;
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck.";
            } else {
                $stockTruckRows          = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery  = true;
            }
        }

        // Step: Transfer battery to this truck
        elseif (isset($_POST['transfer_to_truck'])) {
            $stockTruckDidShowQuery = true;
            $stockTruckShowConfirmClear = false;

            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck before transferring.";
            } elseif ($stockTruckTransferBatteryId === '') {
                $stockTruckError = "Please enter a BatteryID to transfer.";
            } else {
                // Same logic as Transfer, but ToLoc = current truck
                $bid = $stockTruckTransferBatteryId;

                try {
                    // Check battery status
                    $sql = "
                        SELECT 
                            Inventory.BatteryID,
                            Inventory.Battery,
                            Inventory.DateCode,
                            Inventory.Location
                        FROM Inventory
                        WHERE Inventory.BatteryID = :bid
                          AND Inventory.Location NOT IN ('SOLD','SCRAPPED')
                          AND Inventory.StockType = 'BATTERY'
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':bid' => $bid]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$row) {
                        $stockTruckError = "Battery not found, or it is SOLD/SCRAPPED.";
                    } elseif ($row['Location'] === $stockTruckSelectedTruck) {
                        $stockTruckError = "Battery is already on truck " . $stockTruckSelectedTruck . ".";
                    } else {
                        $fromLoc = $row['Location'];
                        $toLoc   = $stockTruckSelectedTruck;
                        $battery = $row['Battery'];
                        $dateCode= $row['DateCode'];

                        $pdo->beginTransaction();

                        // Re-check current location in transaction
                        $check = $pdo->prepare("
                            SELECT Location
                            FROM Inventory
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                              AND StockType = 'BATTERY'
                        ");
                        $check->execute([':bid' => $bid]);
                        $current = $check->fetch(PDO::FETCH_ASSOC);

                        if (!$current || $current['Location'] !== $fromLoc) {
                            $pdo->rollBack();
                            $stockTruckError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                        } else {
                            $now = date('Y-m-d H:i:s');

                            // Update Inventory
                            $update = $pdo->prepare("
                                UPDATE Inventory
                                SET Location = :toLoc
                                WHERE BatteryID = :bid
                                  AND StockType = 'BATTERY'
                            ");
                            $update->execute([
                                ':toLoc' => $toLoc,
                                ':bid'   => $bid
                            ]);

                            // Insert AuditLog
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                                VALUES
                                    (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':toLoc'      => $toLoc,
                                ':batteryId'  => $bid,
                                ':battery'    => $battery,
                                ':dateCode'   => $dateCode,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            $stockTruckMessage           = "Battery $bid transferred to truck $toLoc.";
                            $stockTruckTransferBatteryId = ""; // clear BatteryID after transfer
                        }
                    }

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $stockTruckError = "Error transferring battery to truck: " . $e->getMessage();
                }
            }

            // Always rerun query (if truck selected)
            if ($stockTruckSelectedTruck !== '') {
                $stockTruckRows         = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery = true;
            }
        }

        // Step 1: User clicked "Clear Truck Inventory" (show warning + shop)
        elseif (isset($_POST['start_clear'])) {
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck before clearing.";
            } else {
                $stockTruckRows            = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery    = true;
                $stockTruckShowConfirmClear = true;
            }
        }

        // Step 2: Process the clear after warning + shop select
        elseif (isset($_POST['process_clear'])) {
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck before clearing.";
                $stockTruckShowConfirmClear = true;
            } elseif ($stockTruckSelectedShop === '') {
                $stockTruckError = "Please select a shop to move inventory to.";
                $stockTruckShowConfirmClear = true;
            } else {
                try {
                    $pdo->beginTransaction();

                    // Select all battery inventory on that truck
                    $selInv = $pdo->prepare("
                        SELECT BatteryID, Battery, DateCode
                        FROM Inventory
                        WHERE Location = :truck
                          AND StockType = 'BATTERY'
                    ");
                    $selInv->execute([':truck' => $stockTruckSelectedTruck]);
                    $truckInvRows = $selInv->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($truckInvRows)) {
                        $pdo->rollBack();
                        $stockTruckError = "No battery inventory found on truck " . $stockTruckSelectedTruck . ".";
                        $stockTruckShowConfirmClear = true;
                    } else {
                        // Move inventory to selected shop
                        $updInv = $pdo->prepare("
                            UPDATE Inventory
                            SET Location = :shop
                            WHERE Location = :truck
                              AND StockType = 'BATTERY'
                        ");
                        $updInv->execute([
                            ':shop'  => $stockTruckSelectedShop,
                            ':truck' => $stockTruckSelectedTruck
                        ]);

                        // Insert one AuditLog record per battery
                        $insAudit = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'ClearTruck', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                        ");

                        $now = date('Y-m-d H:i:s');

                        foreach ($truckInvRows as $r) {
                            $insAudit->execute([
                                ':empId'     => $empAAA,
                                ':empName'   => $empName,
                                ':fromLoc'   => $stockTruckSelectedTruck,
                                ':toLoc'     => $stockTruckSelectedShop,
                                ':batteryId' => $r['BatteryID'],
                                ':battery'   => $r['Battery'],
                                ':dateCode'  => $r['DateCode'],
                                ':lastUpdate'=> $now,
                            ]);
                        }

                        $pdo->commit();

                        $stockTruckMessage           = "Truck " . $stockTruckSelectedTruck .
                            " inventory successfully moved to " . $stockTruckSelectedShop . ".";
                        $stockTruckRows              = [];
                        $stockTruckShowConfirmClear  = false;
                        $stockTruckDidShowQuery      = true;
                    }

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $stockTruckError = "Error clearing truck: " . $e->getMessage();
                    $stockTruckShowConfirmClear = true;
                }
            }

            // Re-run query to show updated view (may be empty after clear)
            if ($stockTruckSelectedTruck !== '') {
                $stockTruckRows         = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery = true;
            }
        }
    }
}

// ===== HISTORY SECTION =====
if ($view === 'history') {
    try {
        $stmtHist = $pdo->prepare("
            SELECT 
                BatteryID,
                Battery,
                Type,
                ToLoc,
                FromLoc,
                LastUpdate
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND StockType = 'BATTERY'
              AND Type NOT IN ('Log On', 'Receive')
            ORDER BY LastUpdate DESC
            LIMIT 25
        ");
        $stmtHist->execute([':empId' => $empAAA]);
        $historyRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $historyRows = [];
    }
}

// ===== SOLD TODAY COUNT (MENU ONLY, BASED ON EST/EDT) =====
if ($view === 'menu') {
    try {
        // Local "today" in EST/EDT
        $startToday = date('Y-m-d 00:00:00');
        $endToday   = date('Y-m-d 23:59:59');

        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND LastUpdate >= :startToday
              AND LastUpdate <= :endToday
        ");
        $stmtSold->execute([
            ':empId'      => $empAAA,
            ':startToday' => $startToday,
            ':endToday'   => $endToday,
        ]);
        $soldTodayCount = (int)$stmtSold->fetchColumn();
    } catch (Exception $e) {
        $soldTodayCount = 0; // fail quietly
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
                grid-template-columns: repeat(4, 1fr);
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
        select, input[type="text"], textarea {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        textarea {
            min-height: 80px;
            resize: vertical;
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
        .mt-6  { margin-top: 6px; }
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php elseif ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php elseif ($msg === 'scrapped'): ?>
        <div class="msg msg-success">
            Battery was successfully scrapped and logged.
        </div>
    <?php endif; ?>

    <?php if ($view === 'menu'): ?>

        <h2>Main Menu</h2>
        <div class="menu-grid">
            <a class="btn" href="?view=inventory">Inventory</a>
            <a class="btn" href="?view=sell">Sell Battery</a>
            <a class="btn" href="?view=stocktruck">Stock Truck</a>
            <a class="btn" href="?view=transfer">Transfer Battery</a>
            <a class="btn" href="?view=scrap">Scrap Battery</a>
            <a class="btn" href="?view=history">History</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong> (<?= htmlspecialchars($empAAA) ?>).<br>
                Use the buttons above to manage batteries.
            </p>

            <?php if ($soldTodayCount > 0): ?>
                <p class="text-center" style="font-size:13px; color:#166534; margin-top:6px;">
                    You have sold <strong><?= $soldTodayCount ?></strong>
                    battery<?= ($soldTodayCount === 1 ? '' : 'ies') ?> so far today.
                </p>
            <?php endif; ?>

            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary" style="max-width:200px; display:inline-block;">
                    Logout
                </a>
            </div>
        </div>

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

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="sell_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('sell_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not previously <strong>SOLD</strong> or <strong>SCRAPPED</strong> are eligible.
            </p>
        </div>

        <!-- Step 2: Confirm Sale -->
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

        <?php if (!empty($transferSuccess)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($transferSuccess) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: BatteryID + Destination -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="transfer_battery_id"
                           style="flex:1;"
                           value="<?= htmlspecialchars($transferBatteryId ?? '') ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('transfer_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <label class="label-block mt-10">Transfer To</label>
                <select name="to_loc">
                    <option value="">Select Destination</option>
                    <?php foreach ($destRows as $d): ?>
                        <?php
                        $label = $d['ToLoc'] . ' (' . $d['Type'] . ')';
                        $val   = $d['ToLoc'];
                        ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                            <?= ($val === ($transferToLoc ?? '')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="preview_transfer" class="btn mt-10">
                    Preview Transfer
                </button>
            </form>

            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be transferred.
            </p>
        </div>

        <!-- Step 2: Preview + Confirm -->
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

    <?php elseif ($view === 'scrap'): ?>

        <h2>Scrap a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($scrapError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($scrapError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="scrap_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('scrap_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be scrapped.
            </p>
        </div>

        <!-- Step 2: Confirm Scrap + Reason -->
        <?php if ($scrapInfo): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Scrap</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($scrapInfo['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($scrapInfo['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($scrapInfo['DateCode']) ?></p>
                <p><strong>Current Location:</strong> <?= htmlspecialchars($scrapInfo['Location']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($scrapInfo['BatteryID']) ?>">

                    <label class="label-block">Reason for Scrap (required, max 255 chars)</label>
                    <textarea name="reason" maxlength="255"
                              placeholder="Describe why this battery is being scrapped."><?= isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : '' ?></textarea>

                    <button type="submit" name="confirm_scrap" class="btn mt-10">
                        Scrap This Battery
                    </button>
                </form>

                <p class="mt-6 small-note">
                    Reason text will be cleaned (for example, single quotes removed) before being stored in the audit log.
                </p>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'stocktruck'): ?>

        <h2>Stock Truck</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($stockTruckError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($stockTruckError) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($stockTruckMessage)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($stockTruckMessage) ?>
            </div>
        <?php endif; ?>

        <!-- Select Truck & Show Stock -->
        <div class="card">
            <form method="post">
                <label class="label-block">Truck</label>
                <select name="truck">
                    <option value="">Select Truck</option>
                    <?php foreach ($stockTruckTruckList as $truck): ?>
                        <option value="<?= htmlspecialchars($truck) ?>"
                            <?= ($truck === $stockTruckSelectedTruck ? 'selected' : '') ?>>
                            <?= htmlspecialchars($truck) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="show_truck" class="btn mt-10">
                    Show Truck Stock
                </button>
            </form>
            <p class="mt-6 small-note">
                Shows batteries where current on-truck quantity does not match the defined minimums.
            </p>
        </div>

        <!-- Stock Results -->
        <?php if ($stockTruckDidShowQuery && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Truck Stock for <?= htmlspecialchars($stockTruckSelectedTruck) ?></h3>
                <div class="table-container">
                    <table>
                        <tr>
                            <th>Battery</th>
                            <th>Current</th>
                            <th>Min</th>
                            <th>Need</th>
                        </tr>
                        <?php if (empty($stockTruckRows)): ?>
                            <tr>
                                <td colspan="4" class="text-center">No variances found for this truck.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stockTruckRows as $row): ?>
                                <?php $needVal = (int)($row['Need'] ?? 0); ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['Battery'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['Current'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['Min'] ?? '') ?></td>
                                    <td style="<?= $needVal < 0 ? 'color:#b91c1c;font-weight:bold;' : '' ?>">
                                        <?= htmlspecialchars($row['Need'] ?? '') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>
                <p class="small-note mt-6">
                    Negative Need means the truck currently has more than its defined minimum; those values are shown in red.
                </p>
            </div>
        <?php endif; ?>

        <!-- Transfer battery TO this truck (only after query) -->
        <?php if ($stockTruckDidShowQuery && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Transfer Battery to This Truck</h3>
                <form method="post">
                    <input type="hidden" name="truck" value="<?= htmlspecialchars($stockTruckSelectedTruck) ?>">

                    <label class="label-block">BatteryID</label>
                    <div style="display:flex; gap:6px;">
                        <input type="text" name="battery_id" id="stocktruck_transfer_battery_id"
                               style="flex:1;"
                               value="<?= htmlspecialchars($stockTruckTransferBatteryId ?? '') ?>"
                               placeholder="Enter or Scan BatteryID">
                        <button type="button"
                                onclick="openScanner('stocktruck_transfer_battery_id')"
                                class="btn"
                                style="width:auto; padding:0 10px;">
                            Scan
                        </button>
                    </div>

                    <button type="submit" name="transfer_to_truck" class="btn mt-10">
                        Transfer Battery to <?= htmlspecialchars($stockTruckSelectedTruck) ?>
                    </button>
                </form>
                <p class="mt-6 small-note">
                    Uses the same transfer logic as the Transfer Battery screen, but sends the battery directly to this truck.
                </p>
            </div>
        <?php endif; ?>

        <!-- Step 1: Show Clear Truck option ONLY after query has been run -->
        <?php if ($stockTruckDidShowQuery && !$stockTruckShowConfirmClear && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Clear Truck Inventory</h3>
                <form method="post">
                    <input type="hidden" name="truck" value="<?= htmlspecialchars($stockTruckSelectedTruck) ?>">
                    <button type="submit" name="start_clear" class="btn mt-10">
                        Clear This Truck
                    </button>
                </form>
                <p class="mt-6 small-note">
                    Use this if you want to move <strong>all BATTERY inventory</strong> from this truck to a shop.
                </p>
            </div>
        <?php endif; ?>

        <!-- Step 2: Warning + Shop selection + Process button -->
        <?php if ($stockTruckShowConfirmClear && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Clear Truck</h3>

                <p class="small-note" style="color:#b91c1c; font-weight:bold;">
                    Warning: Continuing will move <strong>all BATTERY inventory</strong> from truck
                    <strong><?= htmlspecialchars($stockTruckSelectedTruck) ?></strong>
                    to the selected shop location. This action cannot be undone.
                </p>

                <form method="post">
                    <input type="hidden" name="truck" value="<?= htmlspecialchars($stockTruckSelectedTruck) ?>">

                    <label class="label-block mt-10">Move inventory to Shop</label>
                    <select name="shop_loc">
                        <option value="">Select Shop Location</option>
                        <?php foreach ($stockTruckShopList as $shop): ?>
                            <option value="<?= htmlspecialchars($shop) ?>"
                                <?= ($shop === $stockTruckSelectedShop ? 'selected' : '') ?>>
                                <?= htmlspecialchars($shop) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" name="process_clear" class="btn mt-10">
                        Process Clear Truck
                    </button>
                </form>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'history'): ?>

        <h2>History</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="small-note">
                Showing the most recent 25 events for <strong><?= htmlspecialchars($empName) ?></strong>.
            </p>
            <div class="table-container">
                <table>
                    <tr>
                        <th>BatteryID</th>
                        <th>Battery</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Time/Date</th>
                    </tr>
                    <?php if (empty($historyRows)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No history found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historyRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['BatteryID']  ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Battery']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Type']       ?? '') ?></td>
                                <td><?= htmlspecialchars($row['FromLoc']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['ToLoc']      ?? '') ?></td>
                                <td><?= htmlspecialchars($row['LastUpdate'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Barcode Scanner Modal -->
<div id="scannerOverlay" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.8);
    z-index:9999;
    align-items:center;
    justify-content:center;
">
    <div style="background:#fff; padding:10px; border-radius:8px; max-width:400px; width:90%; text-align:center;">
        <h3 style="margin-top:0;">Scan Battery Barcode</h3>
        <video id="scannerVideo" style="width:100%; max-height:300px; background:#000;"></video>
        <p style="font-size:12px; color:#6b7280; margin-top:6px;">
            Align the barcode within the frame until it is detected.
        </p>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:8px;">
            <button type="button" onclick="switchCamera()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#2563eb;
                color:#fff;
            ">
                Switch Camera
            </button>
            <button type="button" onclick="closeScanner()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#4b5563;
                color:#fff;
            ">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ZXing and scanner script -->
<script src="https://unpkg.com/@zxing/library@latest"></script>
<script>
    let selectedInputId = null;
    let codeReader = null;
    let videoInputDevices = [];
    let currentDeviceIndex = 0;
    let currentStream = null;
    let audioCtx = null;

    function playBeep() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
            }
            const duration = 0.15; // seconds
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5-ish
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    }

    function stopCurrentStream() {
        const video = document.getElementById('scannerVideo');
        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }
        if (video) {
            video.srcObject = null;
        }
    }

    async function startDecodingWithCurrentDevice() {
        if (!videoInputDevices.length) return;

        const device = videoInputDevices[currentDeviceIndex];
        const deviceId = device.deviceId;

        stopCurrentStream();

        try {
            const video = document.getElementById('scannerVideo');

            // ZXing will call getUserMedia under the hood
            codeReader.decodeFromVideoDevice(deviceId, 'scannerVideo', (result, err) => {
                if (result) {
                    const input = document.getElementById(selectedInputId);
                    if (input) {
                        input.value = result.text;
                    }
                    playBeep();
                    closeScanner();
                }
                // err is usually "NotFoundException" while scanning – safe to ignore
            });

            // Save the stream from the video element once it starts
            setTimeout(() => {
                if (video && video.srcObject) {
                    currentStream = video.srcObject;
                }
            }, 500);

        } catch (e) {
            console.error('Error starting decode:', e);
            alert('Unable to start camera. Please check permissions.');
            closeScanner();
        }
    }

    function pickBackCameraIndex(devices) {
        // Prefer labels that look like back/rear/environment
        let idx = devices.findIndex(d =>
            /back|rear|environment/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Next, prefer anything that is NOT clearly front
        idx = devices.findIndex(d =>
            !/front|user/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Fallback to first
        return 0;
    }

    async function openScanner(inputId) {
        selectedInputId = inputId;
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'flex';

        if (!codeReader) {
            codeReader = new ZXing.BrowserMultiFormatReader();
        }

        try {
            // Get list of cameras
            videoInputDevices = await codeReader.listVideoInputDevices();
            if (!videoInputDevices.length) {
                alert('No camera found on this device.');
                closeScanner();
                return;
            }

            currentDeviceIndex = pickBackCameraIndex(videoInputDevices);
            await startDecodingWithCurrentDevice();
        } catch (e) {
            console.error(e);
            alert('Unable to access camera. Please check permissions.');
            closeScanner();
        }
    }

    function switchCamera() {
        if (!videoInputDevices.length || !codeReader) return;

        try {
            codeReader.reset();
        } catch (e) {
            console.warn(e);
        }
        stopCurrentStream();

        currentDeviceIndex = (currentDeviceIndex + 1) % videoInputDevices.length;
        startDecodingWithCurrentDevice();
    }

    function closeScanner() {
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'none';

        if (codeReader) {
            try {
                codeReader.reset();
            } catch (e) {
                console.warn(e);
            }
        }
        stopCurrentStream();
    }
</script>
</body>
</html>
If you hit any weird behavior (like the truck transfer and clear flows stepping on each other’s messages), tell me what you see on-screen and I’ll tighten up the state handling.

You said:
The Inventory Table is being renamed BatteryInventory and the following fields are being removed from it
Make, Model, Description, Quantity, StockType
ChatGPT said:

Thought for 28s
Gotcha — Inventory is now BatteryInventory, and Make/Model/Description/Quantity/StockType are removed from that table.

Below is your full updated build with:

All table references changed from Inventory → BatteryInventory.

All StockType = 'BATTERY' filters on the inventory table removed (since that column is gone).

Any inventory Quantity references removed from SQL (they were only using COUNT(), so no real logical change).

AuditLog still logs StockType = 'BATTERY' and Quantity = 1 as before.

<?php
// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
    // Clear session data
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        // Session expired
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    } else {
        // Still active, refresh timer
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE (NO OUTPUT BEFORE THIS POINT) =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Please enter both AAA and Password.";
        } else {
            try {
                $dsnLogin = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
                $pdoLogin = new PDO($dsnLogin, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "
                    SELECT AAA, FirstName, LastName
                    FROM Employee
                    WHERE AAA = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA'];
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']     = true;
                    $_SESSION['empAAA']        = $empId;
                    $_SESSION['empFirst']      = $emp['FirstName'];
                    $_SESSION['empLast']       = $emp['LastName'];
                    $_SESSION['empName']       = $empName;
                    $_SESSION['last_activity'] = time(); // start timeout timer

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }

            } catch (Exception $e) {
                $error = "Login error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Browns Towing Battery Program Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2 style="text-align:center;">Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p style="color:orange;">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>AAA:</label><br>
            <input type="text" name="aaa"
                   style="width:100%; padding:8px; margin:8px 0;"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

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

// ===== WE HAVE A LOGGED IN USER =====
$empAAA  = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName = $_SESSION['empName'] ?? 'Tuna Marie';

// ===== CONNECT TO DB =====
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE =====
// views: menu | inventory | sell | transfer | scrap | stocktruck | history
$view = $_GET['view'] ?? 'menu';
$msg  = $_GET['msg']  ?? '';

// Predeclare vars
$invRows        = [];
$allLocations   = [];
$allBatteries   = [];

$sellError      = "";
$sellInfo       = null;

$transferError      = "";
$transferSuccess    = "";
$transferPreview    = null;
$transferToLoc      = "";
$transferBatteryId  = "";
$destRows           = [];

$scrapError      = "";
$scrapInfo       = null;

$historyRows     = [];

$soldTodayCount  = 0;

// Stock Truck
$stockTruckError             = "";
$stockTruckMessage           = "";
$stockTruckSelectedTruck     = "";
$stockTruckSelectedShop      = "";
$stockTruckRows              = [];
$stockTruckTruckList         = [];
$stockTruckShopList          = [];
$stockTruckDidShowQuery      = false;
$stockTruckShowConfirmClear  = false;
$stockTruckTransferBatteryId = "";

// ===== INVENTORY SECTION (BatteryInventory) =====
if ($view === 'inventory') {
    $selectedLocation = isset($_GET['loc']) ? trim($_GET['loc']) : '';
    $selectedBattery  = isset($_GET['bat']) ? trim($_GET['bat']) : '';

    // Dropdown data (NO SOLD / SCRAPPED) - BatteryInventory
    $sqlAll = "
        SELECT 
            BatteryInventory.Battery AS Battery,
            BatteryInventory.Location AS Location
        FROM BatteryInventory
        WHERE BatteryInventory.Location NOT IN ('SOLD','SCRAPPED')
        GROUP BY BatteryInventory.Battery, BatteryInventory.Location
        ORDER BY BatteryInventory.Battery, BatteryInventory.Location
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

    // Aggregated query (NO SOLD / SCRAPPED)
    $sql = "
        SELECT 
            BatteryInventory.Battery AS Battery,
            COUNT(*) AS Quantity,
            BatteryInventory.Location AS Location
        FROM BatteryInventory
        WHERE BatteryInventory.Location NOT IN ('SOLD','SCRAPPED')
    ";
    $params = [];

    if ($selectedLocation !== '') {
        $sql .= " AND BatteryInventory.Location = :loc";
        $params[':loc'] = $selectedLocation;
    }
    if ($selectedBattery !== '') {
        $sql .= " AND BatteryInventory.Battery = :bat";
        $params[':bat'] = $selectedBattery;
    }

    $sql .= "
        GROUP BY BatteryInventory.Battery, BatteryInventory.Location
        ORDER BY BatteryInventory.Battery, BatteryInventory.Location
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== SELL BATTERY SECTION =====
if ($view === 'sell') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $sellError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        BatteryInventory.BatteryID,
                        BatteryInventory.Battery,
                        BatteryInventory.DateCode,
                        BatteryInventory.Location
                    FROM BatteryInventory
                    WHERE BatteryInventory.BatteryID = :bid
                      AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm sale
        elseif (isset($_POST['confirm_sell'])) {
            $bid = trim($_POST['battery_id'] ?? '');

            if ($bid === '') {
                $sellError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        BatteryInventory.BatteryID,
                        BatteryInventory.Battery,
                        BatteryInventory.DateCode,
                        BatteryInventory.Location
                    FROM BatteryInventory
                    WHERE BatteryInventory.BatteryID = :bid
                      AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    try {
                        $pdo->beginTransaction();

                        $fromLoc = $row['Location'];
                        $now     = date('Y-m-d H:i:s');

                        // Update BatteryInventory
                        $update = $pdo->prepare("
                            UPDATE BatteryInventory 
                            SET Location = 'SOLD' 
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                            VALUES
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':batteryId'  => $row['BatteryID'],
                            ':battery'    => $row['Battery'],
                            ':dateCode'   => $row['DateCode'],
                            ':lastUpdate' => $now,
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

// ===== TRANSFER BATTERY SECTION =====
if ($view === 'transfer') {
    // Build combined destination list: shops + trucks
    $stmtDest = $pdo->query("
        SELECT Location AS ToLoc, 'SHOP' AS Type
        FROM Location
        UNION ALL
        SELECT Truck AS ToLoc, 'TRUCK' AS Type
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
    ");
    $rowsDest = $stmtDest->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($rowsDest as $r) {
        $loc  = trim($r['ToLoc'] ?? '');
        $type = $r['Type'] ?? 'TRUCK';
        if ($loc === '') continue;
        if (!isset($seen[$loc])) {
            $seen[$loc] = $type;
        }
    }

    foreach ($seen as $loc => $type) {
        $destRows[] = [
            'ToLoc' => $loc,
            'Type'  => $type
        ];
    }

    // Sort: shops first, then trucks; each group ascending by name
    usort($destRows, function($a, $b) {
        if ($a['Type'] !== $b['Type']) {
            return ($a['Type'] === 'SHOP') ? -1 : 1;
        }
        return strcasecmp($a['ToLoc'], $b['ToLoc']);
    });

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Preview transfer
        if (isset($_POST['preview_transfer'])) {
            $bid        = trim($_POST['battery_id'] ?? '');
            $transferTo = trim($_POST['to_loc'] ?? '');
            $transferToLoc     = $transferTo;
            $transferBatteryId = $bid;

            if ($bid === '') {
                $transferError = "Please enter a BatteryID.";
            } elseif ($transferTo === '') {
                $transferError = "Please select a destination.";
            } else {
                $sql = "
                    SELECT 
                        BatteryInventory.BatteryID,
                        BatteryInventory.Battery,
                        BatteryInventory.DateCode,
                        BatteryInventory.Location
                    FROM BatteryInventory
                    WHERE BatteryInventory.BatteryID = :bid
                      AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED.";
                } else {
                    $fromLoc = $row['Location'];

                    if ($fromLoc === $transferTo) {
                        $transferError = "BatteryID already at location.";
                    } else {
                        $transferPreview = [
                            'BatteryID' => $row['BatteryID'],
                            'Battery'   => $row['Battery'],
                            'DateCode'  => $row['DateCode'],
                            'FromLoc'   => $fromLoc,
                            'ToLoc'     => $transferTo,
                        ];
                    }
                }
            }
        }

        // Step 2: Confirm transfer
        elseif (isset($_POST['confirm_transfer'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $fromLoc   = trim($_POST['from_loc'] ?? '');
            $toLoc     = trim($_POST['to_loc'] ?? '');
            $battery   = trim($_POST['battery'] ?? '');
            $dateCode  = trim($_POST['date_code'] ?? '');

            // keep UI selections
            $transferToLoc     = $toLoc;
            $transferBatteryId = $bid;

            if ($bid === '' || $fromLoc === '' || $toLoc === '') {
                $transferError = "Missing transfer data. Please try again.";
            } elseif ($fromLoc === $toLoc) {
                $transferError = "BatteryID already at location.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $check = $pdo->prepare("
                        SELECT BatteryInventory.Location
                        FROM BatteryInventory
                        WHERE BatteryID = :bid
                          AND Location NOT IN ('SOLD','SCRAPPED')
                    ");
                    $check->execute([':bid' => $bid]);
                    $current = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$current || $current['Location'] !== $fromLoc) {
                        $pdo->rollBack();
                        $transferError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                    } else {
                        $now = date('Y-m-d H:i:s');

                        // Update BatteryInventory
                        $update = $pdo->prepare("
                            UPDATE BatteryInventory
                            SET Location = :toLoc
                            WHERE BatteryID = :bid
                        ");
                        $update->execute([
                            ':toLoc' => $toLoc,
                            ':bid'   => $bid
                        ]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                        ");
                        $insert->execute([
                            ':empId'      => $empAAA,
                            ':empName'    => $empName,
                            ':fromLoc'    => $fromLoc,
                            ':toLoc'      => $toLoc,
                            ':batteryId'  => $bid,
                            ':battery'    => $battery,
                            ':dateCode'   => $dateCode,
                            ':lastUpdate' => $now,
                        ]);

                        $pdo->commit();

                        // Stay on Transfer page and show success
                        $transferSuccess   = "Battery was successfully transferred and logged.";
                        $transferPreview   = null;
                        $transferBatteryId = ""; // clear BatteryID prompt after successful transfer
                    }

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $transferError = "Error transferring battery: " . $e->getMessage();
                }
            }
        }
    }
}

// ===== SCRAP BATTERY SECTION =====
if ($view === 'scrap') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Step 1: Lookup battery
        if (isset($_POST['lookup_battery'])) {
            $inputId = trim($_POST['battery_id'] ?? '');

            if ($inputId === '') {
                $scrapError = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        BatteryInventory.BatteryID,
                        BatteryInventory.Battery,
                        BatteryInventory.DateCode,
                        BatteryInventory.Location
                    FROM BatteryInventory
                    WHERE BatteryInventory.BatteryID = :bid
                      AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $scrapInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$scrapInfo) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                }
            }
        }

        // Step 2: Confirm scrap
        elseif (isset($_POST['confirm_scrap'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $reasonRaw = $_POST['reason'] ?? '';

            // We’ll requery battery info for safety
            if ($bid === '') {
                $scrapError = "Missing BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        BatteryInventory.BatteryID,
                        BatteryInventory.Battery,
                        BatteryInventory.DateCode,
                        BatteryInventory.Location
                    FROM BatteryInventory
                    WHERE BatteryInventory.BatteryID = :bid
                      AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED.";
                } else {
                    // Validate reason
                    $reasonTrim  = trim($reasonRaw);
                    if ($reasonTrim === '') {
                        $scrapError = "Reason is required to scrap a battery.";
                        $scrapInfo  = $row; // keep display
                    } else {
                        // Clean reason: remove single quotes & limit to 255 chars
                        $reasonClean = str_replace("'", "", $reasonTrim);
                        $reasonClean = mb_substr($reasonClean, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $fromLoc = $row['Location'];
                            $now     = date('Y-m-d H:i:s');

                            // Update BatteryInventory to SCRAPPED
                            $update = $pdo->prepare("
                                UPDATE BatteryInventory 
                                SET Location = 'SCRAPPED'
                                WHERE BatteryID = :bid
                                  AND Location NOT IN ('SOLD','SCRAPPED')
                            ");
                            $update->execute([':bid' => $bid]);

                            // Insert AuditLog (ToLoc = SCRAPPED, Reason = user text)
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                                VALUES
                                    (:empId, :empName, :fromLoc, 'SCRAPPED', :batteryId, 'Scrap', '', :battery, :dateCode, :reason, 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':batteryId'  => $row['BatteryID'],
                                ':battery'    => $row['Battery'],
                                ':dateCode'   => $row['DateCode'],
                                ':reason'     => $reasonClean,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            header("Location: " . $_SERVER['PHP_SELF'] . "?view=menu&msg=scrapped");
                            exit;

                        } catch (Exception $e) {
                            $pdo->rollBack();
                            $scrapError = "Error scrapping battery: " . $e->getMessage();
                            $scrapInfo  = $row;
                        }
                    }
                }
            }
        }
    }
}

// ===== STOCK TRUCK SECTION =====
if ($view === 'stocktruck') {
    // Load truck list (from Trucks.Truck)
    $stmtTrucks = $pdo->query("
        SELECT Truck
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
        ORDER BY Truck
    ");
    $stockTruckTruckList = $stmtTrucks->fetchAll(PDO::FETCH_COLUMN);

    // Load shop list (from Location.Location)
    $stmtShops = $pdo->query("
        SELECT Location
        FROM Location
        WHERE Location IS NOT NULL AND Location <> ''
        ORDER BY Location
    ");
    $stockTruckShopList = $stmtShops->fetchAll(PDO::FETCH_COLUMN);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $stockTruckSelectedTruck     = trim($_POST['truck'] ?? '');
        $stockTruckSelectedShop      = trim($_POST['shop_loc'] ?? '');
        $stockTruckTransferBatteryId = trim($_POST['battery_id'] ?? $stockTruckTransferBatteryId);

        // Helper function to run the stock query
        $runStockQuery = function($pdo, $truck) {
            $sqlTruck = "
                SELECT 
                    Battery,
                    Current,
                    Min,
                    Need
                FROM (
                    SELECT 
                        bm.Location,
                        bm.Battery,
                        COUNT(inv.BatteryID) AS Current,
                        bm.Minimum AS Min,
                        (COUNT(inv.BatteryID) - bm.Minimum) * -1 AS Need
                    FROM BatteryMinimum bm
                    LEFT JOIN BatteryInventory inv
                        ON inv.Location = bm.Location
                        AND inv.Battery = bm.Battery
                    WHERE bm.Location = :truck
                    GROUP BY bm.Location, bm.Battery, bm.Minimum

                    UNION ALL

                    SELECT
                        inv.Location,
                        inv.Battery,
                        COUNT(inv.BatteryID) AS Current,
                        0 AS Min,
                        COUNT(inv.BatteryID) * -1 AS Need
                    FROM BatteryInventory inv
                    LEFT JOIN BatteryMinimum bm
                        ON bm.Location = inv.Location
                        AND bm.Battery = inv.Battery
                    WHERE inv.Location = :truck
                      AND bm.Battery IS NULL
                    GROUP BY inv.Location, inv.Battery
                ) AS x
                WHERE Need <> 0
                ORDER BY Battery
            ";
            $stmtTruck = $pdo->prepare($sqlTruck);
            $stmtTruck->execute([':truck' => $truck]);
            return $stmtTruck->fetchAll(PDO::FETCH_ASSOC);
        };

        // Show truck stock
        if (isset($_POST['show_truck'])) {
            $stockTruckShowConfirmClear = false;
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck.";
            } else {
                $stockTruckRows          = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery  = true;
            }
        }

        // Step: Transfer battery to this truck
        elseif (isset($_POST['transfer_to_truck'])) {
            $stockTruckDidShowQuery = true;
            $stockTruckShowConfirmClear = false;

            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck before transferring.";
            } elseif ($stockTruckTransferBatteryId === '') {
                $stockTruckError = "Please enter a BatteryID to transfer.";
            } else {
                // Same logic as Transfer, but ToLoc = current truck
                $bid = $stockTruckTransferBatteryId;

                try {
                    // Check battery status
                    $sql = "
                        SELECT 
                            BatteryInventory.BatteryID,
                            BatteryInventory.Battery,
                            BatteryInventory.DateCode,
                            BatteryInventory.Location
                        FROM BatteryInventory
                        WHERE BatteryInventory.BatteryID = :bid
                          AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED')
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':bid' => $bid]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$row) {
                        $stockTruckError = "Battery not found, or it is SOLD/SCRAPPED.";
                    } elseif ($row['Location'] === $stockTruckSelectedTruck) {
                        $stockTruckError = "Battery is already on truck " . $stockTruckSelectedTruck . ".";
                    } else {
                        $fromLoc = $row['Location'];
                        $toLoc   = $stockTruckSelectedTruck;
                        $battery = $row['Battery'];
                        $dateCode= $row['DateCode'];

                        $pdo->beginTransaction();

                        // Re-check current location in transaction
                        $check = $pdo->prepare("
                            SELECT Location
                            FROM BatteryInventory
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED')
                        ");
                        $check->execute([':bid' => $bid]);
                        $current = $check->fetch(PDO::FETCH_ASSOC);

                        if (!$current || $current['Location'] !== $fromLoc) {
                            $pdo->rollBack();
                            $stockTruckError = "Battery location changed or is now SOLD/SCRAPPED. Refresh and try again.";
                        } else {
                            $now = date('Y-m-d H:i:s');

                            // Update BatteryInventory
                            $update = $pdo->prepare("
                                UPDATE BatteryInventory
                                SET Location = :toLoc
                                WHERE BatteryID = :bid
                            ");
                            $update->execute([
                                ':toLoc' => $toLoc,
                                ':bid'   => $bid
                            ]);

                            // Insert AuditLog
                            $insert = $pdo->prepare("
                                INSERT INTO AuditLog
                                    (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                                VALUES
                                    (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                            ");
                            $insert->execute([
                                ':empId'      => $empAAA,
                                ':empName'    => $empName,
                                ':fromLoc'    => $fromLoc,
                                ':toLoc'      => $toLoc,
                                ':batteryId'  => $bid,
                                ':battery'    => $battery,
                                ':dateCode'   => $dateCode,
                                ':lastUpdate' => $now,
                            ]);

                            $pdo->commit();

                            $stockTruckMessage           = "Battery $bid transferred to truck $toLoc.";
                            $stockTruckTransferBatteryId = ""; // clear BatteryID after transfer
                        }
                    }

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $stockTruckError = "Error transferring battery to truck: " . $e->getMessage();
                }
            }

            // Always rerun query (if truck selected)
            if ($stockTruckSelectedTruck !== '') {
                $stockTruckRows         = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery = true;
            }
        }

        // Step 1: User clicked "Clear Truck Inventory" (show warning + shop)
        elseif (isset($_POST['start_clear'])) {
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck before clearing.";
            } else {
                $stockTruckRows            = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery    = true;
                $stockTruckShowConfirmClear = true;
            }
        }

        // Step 2: Process the clear after warning + shop select
        elseif (isset($_POST['process_clear'])) {
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck before clearing.";
                $stockTruckShowConfirmClear = true;
            } elseif ($stockTruckSelectedShop === '') {
                $stockTruckError = "Please select a shop to move inventory to.";
                $stockTruckShowConfirmClear = true;
            } else {
                try {
                    $pdo->beginTransaction();

                    // Select all battery inventory on that truck
                    $selInv = $pdo->prepare("
                        SELECT BatteryID, Battery, DateCode
                        FROM BatteryInventory
                        WHERE Location = :truck
                    ");
                    $selInv->execute([':truck' => $stockTruckSelectedTruck]);
                    $truckInvRows = $selInv->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($truckInvRows)) {
                        $pdo->rollBack();
                        $stockTruckError = "No battery inventory found on truck " . $stockTruckSelectedTruck . ".";
                        $stockTruckShowConfirmClear = true;
                    } else {
                        // Move inventory to selected shop
                        $updInv = $pdo->prepare("
                            UPDATE BatteryInventory
                            SET Location = :shop
                            WHERE Location = :truck
                        ");
                        $updInv->execute([
                            ':shop'  => $stockTruckSelectedShop,
                            ':truck' => $stockTruckSelectedTruck
                        ]);

                        // Insert one AuditLog record per battery
                        $insAudit = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                            VALUES
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'ClearTruck', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                        ");

                        $now = date('Y-m-d H:i:s');

                        foreach ($truckInvRows as $r) {
                            $insAudit->execute([
                                ':empId'     => $empAAA,
                                ':empName'   => $empName,
                                ':fromLoc'   => $stockTruckSelectedTruck,
                                ':toLoc'     => $stockTruckSelectedShop,
                                ':batteryId' => $r['BatteryID'],
                                ':battery'   => $r['Battery'],
                                ':dateCode'  => $r['DateCode'],
                                ':lastUpdate'=> $now,
                            ]);
                        }

                        $pdo->commit();

                        $stockTruckMessage           = "Truck " . $stockTruckSelectedTruck .
                            " inventory successfully moved to " . $stockTruckSelectedShop . ".";
                        $stockTruckRows              = [];
                        $stockTruckShowConfirmClear  = false;
                        $stockTruckDidShowQuery      = true;
                    }

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $stockTruckError = "Error clearing truck: " . $e->getMessage();
                    $stockTruckShowConfirmClear = true;
                }
            }

            // Re-run query to show updated view (may be empty after clear)
            if ($stockTruckSelectedTruck !== '') {
                $stockTruckRows         = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery = true;
            }
        }
    }
}

// ===== HISTORY SECTION =====
if ($view === 'history') {
    try {
        $stmtHist = $pdo->prepare("
            SELECT 
                BatteryID,
                Battery,
                Type,
                ToLoc,
                FromLoc,
                LastUpdate
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND StockType = 'BATTERY'
              AND Type NOT IN ('Log On', 'Receive')
            ORDER BY LastUpdate DESC
            LIMIT 25
        ");
        $stmtHist->execute([':empId' => $empAAA]);
        $historyRows = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $historyRows = [];
    }
}

// ===== SOLD TODAY COUNT (MENU ONLY, BASED ON EST/EDT) =====
if ($view === 'menu') {
    try {
        // Local "today" in EST/EDT
        $startToday = date('Y-m-d 00:00:00');
        $endToday   = date('Y-m-d 23:59:59');

        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND LastUpdate >= :startToday
              AND LastUpdate <= :endToday
        ");
        $stmtSold->execute([
            ':empId'      => $empAAA,
            ':startToday' => $startToday,
            ':endToday'   => $endToday,
        ]);
        $soldTodayCount = (int)$stmtSold->fetchColumn();
    } catch (Exception $e) {
        $soldTodayCount = 0; // fail quietly
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
                grid-template-columns: repeat(4, 1fr);
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
        select, input[type="text"], textarea {
            padding: 6px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            width: 100%;
            box-sizing: border-box;
        }
        textarea {
            min-height: 80px;
            resize: vertical;
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
        .mt-6  { margin-top: 6px; }
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php elseif ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php elseif ($msg === 'scrapped'): ?>
        <div class="msg msg-success">
            Battery was successfully scrapped and logged.
        </div>
    <?php endif; ?>

    <?php if ($view === 'menu'): ?>

        <h2>Main Menu</h2>
        <div class="menu-grid">
            <a class="btn" href="?view=inventory">Inventory</a>
            <a class="btn" href="?view=sell">Sell Battery</a>
            <a class="btn" href="?view=stocktruck">Stock Truck</a>
            <a class="btn" href="?view=transfer">Transfer Battery</a>
            <a class="btn" href="?view=scrap">Scrap Battery</a>
            <a class="btn" href="?view=history">History</a>
        </div>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong> (<?= htmlspecialchars($empAAA) ?>).<br>
                Use the buttons above to manage batteries.
            </p>

            <?php if ($soldTodayCount > 0): ?>
                <p class="text-center" style="font-size:13px; color:#166534; margin-top:6px;">
                    You have sold <strong><?= $soldTodayCount ?></strong>
                    battery<?= ($soldTodayCount === 1 ? '' : 'ies') ?> so far today.
                </p>
            <?php endif; ?>

            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary" style="max-width:200px; display:inline-block;">
                    Logout
                </a>
            </div>
        </div>

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

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="sell_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('sell_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not previously <strong>SOLD</strong> or <strong>SCRAPPED</strong> are eligible.
            </p>
        </div>

        <!-- Step 2: Confirm Sale -->
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

        <?php if (!empty($transferSuccess)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($transferSuccess) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: BatteryID + Destination -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="transfer_battery_id"
                           style="flex:1;"
                           value="<?= htmlspecialchars($transferBatteryId ?? '') ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('transfer_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <label class="label-block mt-10">Transfer To</label>
                <select name="to_loc">
                    <option value="">Select Destination</option>
                    <?php foreach ($destRows as $d): ?>
                        <?php
                        $label = $d['ToLoc'] . ' (' . $d['Type'] . ')';
                        $val   = $d['ToLoc'];
                        ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                            <?= ($val === ($transferToLoc ?? '')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="preview_transfer" class="btn mt-10">
                    Preview Transfer
                </button>
            </form>

            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be transferred.
            </p>
        </div>

        <!-- Step 2: Preview + Confirm -->
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

    <?php elseif ($view === 'scrap'): ?>

        <h2>Scrap a Battery</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($scrapError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($scrapError) ?>
            </div>
        <?php endif; ?>

        <!-- Step 1: Lookup -->
        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <div style="display:flex; gap:6px;">
                    <input type="text" name="battery_id" id="scrap_battery_id"
                           style="flex:1;"
                           value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                           placeholder="Enter or Scan BatteryID">
                    <button type="button"
                            onclick="openScanner('scrap_battery_id')"
                            class="btn"
                            style="width:auto; padding:0 10px;">
                        Scan
                    </button>
                </div>

                <button type="submit" name="lookup_battery" class="btn mt-10">
                    Lookup Battery
                </button>
            </form>
            <p class="mt-6 small-note">
                Only batteries not <strong>SOLD</strong> or <strong>SCRAPPED</strong> can be scrapped.
            </p>
        </div>

        <!-- Step 2: Confirm Scrap + Reason -->
        <?php if ($scrapInfo): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Scrap</h3>
                <p><strong>BatteryID:</strong> <?= htmlspecialchars($scrapInfo['BatteryID']) ?></p>
                <p><strong>Battery:</strong> <?= htmlspecialchars($scrapInfo['Battery']) ?></p>
                <p><strong>Date Code:</strong> <?= htmlspecialchars($scrapInfo['DateCode']) ?></p>
                <p><strong>Current Location:</strong> <?= htmlspecialchars($scrapInfo['Location']) ?></p>

                <form method="post" class="mt-10">
                    <input type="hidden" name="battery_id"
                           value="<?= htmlspecialchars($scrapInfo['BatteryID']) ?>">

                    <label class="label-block">Reason for Scrap (required, max 255 chars)</label>
                    <textarea name="reason" maxlength="255"
                              placeholder="Describe why this battery is being scrapped."><?= isset($_POST['reason']) ? htmlspecialchars($_POST['reason']) : '' ?></textarea>

                    <button type="submit" name="confirm_scrap" class="btn mt-10">
                        Scrap This Battery
                    </button>
                </form>

                <p class="mt-6 small-note">
                    Reason text will be cleaned (for example, single quotes removed) before being stored in the audit log.
                </p>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'stocktruck'): ?>

        <h2>Stock Truck</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <?php if (!empty($stockTruckError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($stockTruckError) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($stockTruckMessage)): ?>
            <div class="card msg msg-success">
                <?= htmlspecialchars($stockTruckMessage) ?>
            </div>
        <?php endif; ?>

        <!-- Select Truck & Show Stock -->
        <div class="card">
            <form method="post">
                <label class="label-block">Truck</label>
                <select name="truck">
                    <option value="">Select Truck</option>
                    <?php foreach ($stockTruckTruckList as $truck): ?>
                        <option value="<?= htmlspecialchars($truck) ?>"
                            <?= ($truck === $stockTruckSelectedTruck ? 'selected' : '') ?>>
                            <?= htmlspecialchars($truck) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="show_truck" class="btn mt-10">
                    Show Truck Stock
                </button>
            </form>
            <p class="mt-6 small-note">
                Shows batteries where current on-truck quantity does not match the defined minimums.
            </p>
        </div>

        <!-- Stock Results -->
        <?php if ($stockTruckDidShowQuery && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Truck Stock for <?= htmlspecialchars($stockTruckSelectedTruck) ?></h3>
                <div class="table-container">
                    <table>
                        <tr>
                            <th>Battery</th>
                            <th>Current</th>
                            <th>Min</th>
                            <th>Need</th>
                        </tr>
                        <?php if (empty($stockTruckRows)): ?>
                            <tr>
                                <td colspan="4" class="text-center">No variances found for this truck.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stockTruckRows as $row): ?>
                                <?php $needVal = (int)($row['Need'] ?? 0); ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['Battery'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['Current'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['Min'] ?? '') ?></td>
                                    <td style="<?= $needVal < 0 ? 'color:#b91c1c;font-weight:bold;' : '' ?>">
                                        <?= htmlspecialchars($row['Need'] ?? '') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>
                <p class="small-note mt-6">
                    Negative Need means the truck currently has more than its defined minimum; those values are shown in red.
                </p>
            </div>
        <?php endif; ?>

        <!-- Transfer battery TO this truck (only after query) -->
        <?php if ($stockTruckDidShowQuery && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Transfer Battery to This Truck</h3>
                <form method="post">
                    <input type="hidden" name="truck" value="<?= htmlspecialchars($stockTruckSelectedTruck) ?>">

                    <label class="label-block">BatteryID</label>
                    <div style="display:flex; gap:6px;">
                        <input type="text" name="battery_id" id="stocktruck_transfer_battery_id"
                               style="flex:1;"
                               value="<?= htmlspecialchars($stockTruckTransferBatteryId ?? '') ?>"
                               placeholder="Enter or Scan BatteryID">
                        <button type="button"
                                onclick="openScanner('stocktruck_transfer_battery_id')"
                                class="btn"
                                style="width:auto; padding:0 10px;">
                            Scan
                        </button>
                    </div>

                    <button type="submit" name="transfer_to_truck" class="btn mt-10">
                        Transfer Battery to <?= htmlspecialchars($stockTruckSelectedTruck) ?>
                    </button>
                </form>
                <p class="mt-6 small-note">
                    Uses the same transfer logic as the Transfer Battery screen, but sends the battery directly to this truck.
                </p>
            </div>
        <?php endif; ?>

        <!-- Step 1: Show Clear Truck option ONLY after query has been run -->
        <?php if ($stockTruckDidShowQuery && !$stockTruckShowConfirmClear && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Clear Truck Inventory</h3>
                <form method="post">
                    <input type="hidden" name="truck" value="<?= htmlspecialchars($stockTruckSelectedTruck) ?>">
                    <button type="submit" name="start_clear" class="btn mt-10">
                        Clear This Truck
                    </button>
                </form>
                <p class="mt-6 small-note">
                    Use this if you want to move <strong>all BATTERY inventory</strong> from this truck to a shop.
                </p>
            </div>
        <?php endif; ?>

        <!-- Step 2: Warning + Shop selection + Process button -->
        <?php if ($stockTruckShowConfirmClear && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Confirm Clear Truck</h3>

                <p class="small-note" style="color:#b91c1c; font-weight:bold;">
                    Warning: Continuing will move <strong>all BATTERY inventory</strong> from truck
                    <strong><?= htmlspecialchars($stockTruckSelectedTruck) ?></strong>
                    to the selected shop location. This action cannot be undone.
                </p>

                <form method="post">
                    <input type="hidden" name="truck" value="<?= htmlspecialchars($stockTruckSelectedTruck) ?>">

                    <label class="label-block mt-10">Move inventory to Shop</label>
                    <select name="shop_loc">
                        <option value="">Select Shop Location</option>
                        <?php foreach ($stockTruckShopList as $shop): ?>
                            <option value="<?= htmlspecialchars($shop) ?>"
                                <?= ($shop === $stockTruckSelectedShop ? 'selected' : '') ?>>
                                <?= htmlspecialchars($shop) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" name="process_clear" class="btn mt-10">
                        Process Clear Truck
                    </button>
                </form>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'history'): ?>

        <h2>History</h2>

        <div class="card">
            <a class="btn btn-secondary" href="?view=menu">Back to Menu</a>
        </div>

        <div class="card">
            <p class="small-note">
                Showing the most recent 25 events for <strong><?= htmlspecialchars($empName) ?></strong>.
            </p>
            <div class="table-container">
                <table>
                    <tr>
                        <th>BatteryID</th>
                        <th>Battery</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Time/Date</th>
                    </tr>
                    <?php if (empty($historyRows)): ?>
                        <tr>
                            <td colspan="6" class="text-center">No history found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($historyRows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['BatteryID']  ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Battery']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['Type']       ?? '') ?></td>
                                <td><?= htmlspecialchars($row['FromLoc']    ?? '') ?></td>
                                <td><?= htmlspecialchars($row['ToLoc']      ?? '') ?></td>
                                <td><?= htmlspecialchars($row['LastUpdate'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Barcode Scanner Modal -->
<div id="scannerOverlay" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.8);
    z-index:9999;
    align-items:center;
    justify-content:center;
">
    <div style="background:#fff; padding:10px; border-radius:8px; max-width:400px; width:90%; text-align:center;">
        <h3 style="margin-top:0;">Scan Battery Barcode</h3>
        <video id="scannerVideo" style="width:100%; max-height:300px; background:#000;"></video>
        <p style="font-size:12px; color:#6b7280; margin-top:6px;">
            Align the barcode within the frame until it is detected.
        </p>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:8px;">
            <button type="button" onclick="switchCamera()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#2563eb;
                color:#fff;
            ">
                Switch Camera
            </button>
            <button type="button" onclick="closeScanner()" style="
                padding:8px 12px;
                border:none;
                border-radius:6px;
                background:#4b5563;
                color:#fff;
            ">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ZXing and scanner script -->
<script src="https://unpkg.com/@zxing/library@latest"></script>
<script>
    let selectedInputId = null;
    let codeReader = null;
    let videoInputDevices = [];
    let currentDeviceIndex = 0;
    let currentStream = null;
    let audioCtx = null;

    function playBeep() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return;
                audioCtx = new AC();
            }
            const duration = 0.15; // seconds
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5-ish
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    }

    function stopCurrentStream() {
        const video = document.getElementById('scannerVideo');
        if (currentStream) {
            currentStream.getTracks().forEach(t => t.stop());
            currentStream = null;
        }
        if (video) {
            video.srcObject = null;
        }
    }

    async function startDecodingWithCurrentDevice() {
        if (!videoInputDevices.length) return;

        const device = videoInputDevices[currentDeviceIndex];
        const deviceId = device.deviceId;

        stopCurrentStream();

        try {
            const video = document.getElementById('scannerVideo');

            // ZXing will call getUserMedia under the hood
            codeReader.decodeFromVideoDevice(deviceId, 'scannerVideo', (result, err) => {
                if (result) {
                    const input = document.getElementById(selectedInputId);
                    if (input) {
                        input.value = result.text;
                    }
                    playBeep();
                    closeScanner();
                }
                // err is usually "NotFoundException" while scanning – safe to ignore
            });

            // Save the stream from the video element once it starts
            setTimeout(() => {
                if (video && video.srcObject) {
                    currentStream = video.srcObject;
                }
            }, 500);

        } catch (e) {
            console.error('Error starting decode:', e);
            alert('Unable to start camera. Please check permissions.');
            closeScanner();
        }
    }

    function pickBackCameraIndex(devices) {
        // Prefer labels that look like back/rear/environment
        let idx = devices.findIndex(d =>
            /back|rear|environment/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Next, prefer anything that is NOT clearly front
        idx = devices.findIndex(d =>
            !/front|user/i.test(d.label)
        );
        if (idx !== -1) return idx;

        // Fallback to first
        return 0;
    }

    async function openScanner(inputId) {
        selectedInputId = inputId;
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'flex';

        if (!codeReader) {
            codeReader = new ZXing.BrowserMultiFormatReader();
        }

        try {
            // Get list of cameras
            videoInputDevices = await codeReader.listVideoInputDevices();
            if (!videoInputDevices.length) {
                alert('No camera found on this device.');
                closeScanner();
                return;
            }

            currentDeviceIndex = pickBackCameraIndex(videoInputDevices);
            await startDecodingWithCurrentDevice();
        } catch (e) {
            console.error(e);
            alert('Unable to access camera. Please check permissions.');
            closeScanner();
        }
    }

    function switchCamera() {
        if (!videoInputDevices.length || !codeReader) return;

        try {
            codeReader.reset();
        } catch (e) {
            console.warn(e);
        }
        stopCurrentStream();

        currentDeviceIndex = (currentDeviceIndex + 1) % videoInputDevices.length;
        startDecodingWithCurrentDevice();
    }

    function closeScanner() {
        const overlay = document.getElementById('scannerOverlay');
        overlay.style.display = 'none';

        if (codeReader) {
            try {
                codeReader.reset();
            } catch (e) {
                console.warn(e);
            }
        }
        stopCurrentStream();
    }
</script>
</body>
</html>
If you tweak any other table/field names (like adding a dedicated lookup table for battery master data), just tell me what changed and I’ll patch this again.

You said:
Are we calling the LastUpdate anywhere in BatteryInventory table?
ChatGPT said:
No — LastUpdate does not exist in the BatteryInventory table anywhere in this build, and we are not writing to it.

Here’s how it currently works in the code you have:

Table	Field	How it’s used
BatteryInventory	No LastUpdate field	Only updates Location on transfer/sell/scrap/clear
AuditLog	LastUpdate	Timestamp is recorded on every transaction (Log On, Sale, Transfer, Scrap, ClearTruck)
So all timestamps are only stored in AuditLog, not directly in the inventory table.

Should BatteryInventory have LastUpdate?
If you want to see when the physical location last changed, it would make sense to add LastUpdate to BatteryInventory and update it with every movement.

If you don’t need reporting on “when did this battery move here,” then keeping that data only in audit history is perfectly valid.

If you want to add it (future enhancement option)
You would need to:

1️⃣ Add the column:

ALTER TABLE BatteryInventory
ADD COLUMN LastUpdate DATETIME NULL;
2️⃣ Update it along with Location changes in:

Sell

Transfer

Scrap

Clear Truck

Example update change:

UPDATE BatteryInventory
SET Location = :toLoc,
    LastUpdate = :lastUpdate
WHERE BatteryID = :bid
That will give you real-time location timestamps.

TL;DR
Current state:

Timestamp tracking = AuditLog only

BatteryInventory = no LastUpdate field → We are not writing to it

If you want, I can:
✔ Add LastUpdate to BatteryInventory
✔ Update every movement transaction
✔ Display it anywhere (inventory view or truck views)

Just tell me:

Do you want BatteryInventory.LastUpdate added and maintained?
(Yes/No)

If yes — do you want to show that timestamp anywhere (Inventory, Transfer preview, Truck Stock, History, etc.)?




No file chosenNo file chosen
ChatGPT can make mistakes. Check important info.
