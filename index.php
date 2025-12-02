<?php
// ===== CONFIG =====
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Force PHP timezone to Eastern
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
                // Force MySQL connection time zone to Eastern Standard Time
                $pdoLogin->exec("SET time_zone = '-05:00'");

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

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', '', 'MOBILE', NOW())
                    ");
                    $insertLogin->execute([
                        ':empId'   => $empId,
                        ':empName' => $empName,
                    ]);

                    // Set session
                    $_SESSION['logged_in']  = true;
                    $_SESSION['empAAA']     = $empId;
                    $_SESSION['empFirst']   = $emp['FirstName'];
                    $_SESSION['empLast']    = $emp['LastName'];
                    $_SESSION['empName']    = $empName;
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
    // Force MySQL connection time zone to Eastern Standard Time
    $pdo->exec("SET time_zone = '-05:00'");
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE =====
// views: menu | inventory | sell | transfer | scrap
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
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'BatterySale', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', NOW())
                        ");
                        $insert->execute([
                            ':empId'    => $empAAA,
                            ':empName'  => $empName,
                            ':fromLoc'  => $fromLoc,
                            ':batteryId'=> $row['BatteryID'],
                            ':battery'  => $row['Battery'],
                            ':dateCode' => $row['DateCode'],
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
                                (:empId, :empName, :fromLoc, :toLoc, :batteryId, 'Transfer', '', :battery, :dateCode, '', :fromLoc, 'MOBILE', NOW())
                        ");
                        $insert->execute([
                            ':empId'    => $empAAA,
                            ':empName'  => $empName,
                            ':fromLoc'  => $fromLoc,
                            ':toLoc'    => $toLoc,
                            ':batteryId'=> $bid,
                            ':battery'  => $battery,
                            ':dateCode' => $dateCode,
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
                                    (:empId, :empName, :fromLoc, 'SCRAPPED', :batteryId, 'Scrap', '', :battery, :dateCode, :reason, :fromLoc, 'MOBILE', NOW())
                            ");
                            $insert->execute([
                                ':empId'    => $empAAA,
                                ':empName'  => $empName,
                                ':fromLoc'  => $fromLoc,
                                ':batteryId'=> $row['BatteryID'],
                                ':battery'  => $row['Battery'],
                                ':dateCode' => $row['DateCode'],
                                ':reason'   => $reasonClean,
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

// ===== SOLD TODAY COUNT (MENU ONLY) =====
if ($view === 'menu') {
    try {
        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND DATE(LastUpdate) = CURRENT_DATE
        ");
        $stmtSold->execute([':empId' => $empAAA]);
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
                <input type="text" name="battery_id"
                       value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                       placeholder="Enter BatteryID">

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
                <input type="text" name="battery_id"
                       value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                       placeholder="Enter BatteryID">

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
                <input type="text" name="battery_id"
                       value="<?= isset($_POST['battery_id']) ? htmlspecialchars($_POST['battery_id']) : '' ?>"
                       placeholder="Enter BatteryID">

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
</body>
</html>
