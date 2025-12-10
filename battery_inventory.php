<?php
// ===== CONFIG =====
$dbHost = getenv('BROWNS_DB_HOST');
$dbName = getenv('BROWNS_DB_NAME');
$dbUser = getenv('BROWNS_DB_USER');
$dbPass = getenv('BROWNS_DB_PASS');

if (!$dbHost || !$dbName || !$dbUser || !$dbPass) {
    die("Database environment variables are not set. Check Render env vars.");
}

// Timezone
date_default_timezone_set('America/New_York');

// Session
session_start();

// Must be logged in
if (empty($_SESSION['logged_in'])) {
    header("Location: index.php");
    exit;
}

// Session timeout (5 minutes) – mirror index.php
$timeoutSeconds = 300;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
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
    header("Location: index.php?msg=timeout");
    exit;
} else {
    $_SESSION['last_activity'] = time();
}

// Session vars
$empAAA         = $_SESSION['empAAA']        ?? 'WEBUSER';
$empName        = $_SESSION['empName']       ?? 'Tuna Marie';
$isManager      = !empty($_SESSION['empManager']);
$isDispatchOnly = !empty($_SESSION['isDispatchOnly'] ?? 0);

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
// views: inventory | sell | transfer | scrap | stocktruck | history
$requestedView = $_GET['view'] ?? 'inventory';
if ($isDispatchOnly) {
    // DISPATCH can only see inventory summary
    $view = 'inventory';
} else {
    $view = $requestedView;
}

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

    // Dropdown data (NO SOLD / SCRAPPED / ROTATED)
    $sqlAll = "
        SELECT 
            BatteryInventory.Battery AS Battery,
            BatteryInventory.Location AS Location
        FROM BatteryInventory
        WHERE BatteryInventory.Location NOT IN ('SOLD','SCRAPPED','ROTATED')
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

    // Aggregated query (NO SOLD / SCRAPPED / ROTATED)
    $sql = "
        SELECT 
            BatteryInventory.Battery AS Battery,
            COUNT(*) AS Quantity,
            BatteryInventory.Location AS Location
        FROM BatteryInventory
        WHERE BatteryInventory.Location NOT IN ('SOLD','SCRAPPED','ROTATED')
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
                      AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED','ROTATED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $sellInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sellInfo) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED/ROTATED.";
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
                      AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED','ROTATED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $sellError = "Battery not found, or it is already SOLD/SCRAPPED/ROTATED.";
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
                              AND Location NOT IN ('SOLD','SCRAPPED','ROTATED')
                        ");
                        $update->execute([':bid' => $bid]);

                        // Insert AuditLog
                        $insert = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity)
                            VALUES
                                (:empId, :empName, :fromLoc, 'SOLD', :batteryId, 'Battery Sale', '', :battery, :dateCode, '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
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

                        // Return to main menu on index with message
                        header("Location: index.php?view=menu&msg=sold");
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
                      AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED','ROTATED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $transferError = "Battery not found, or it is SOLD/SCRAPPED/ROTATED.";
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
                          AND Location NOT IN ('SOLD','SCRAPPED','ROTATED')
                    ");
                    $check->execute([':bid' => $bid]);
                    $current = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$current || $current['Location'] !== $fromLoc) {
                        $pdo->rollBack();
                        $transferError = "Battery location changed or is now SOLD/SCRAPPED/ROTATED. Refresh and try again.";
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

                        $transferSuccess   = "Battery was successfully transferred and logged.";
                        $transferPreview   = null;
                        $transferBatteryId = "";
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
                      AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED','ROTATED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $inputId]);
                $scrapInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$scrapInfo) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED/ROTATED.";
                }
            }
        }

        // Step 2: Confirm scrap
        elseif (isset($_POST['confirm_scrap'])) {
            $bid       = trim($_POST['battery_id'] ?? '');
            $reasonRaw = $_POST['reason'] ?? '';

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
                      AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED','ROTATED')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $bid]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $scrapError = "Battery not found, or it is already SOLD/SCRAPPED/ROTATED.";
                } else {
                    $reasonTrim  = trim($reasonRaw);
                    if ($reasonTrim === '') {
                        $scrapError = "Reason is required to scrap a battery.";
                        $scrapInfo  = $row;
                    } else {
                        $reasonClean = str_replace("'", "", $reasonTrim);
                        $reasonClean = mb_substr($reasonClean, 0, 255);

                        try {
                            $pdo->beginTransaction();

                            $fromLoc = $row['Location'];
                            $now     = date('Y-m-d H:i:s');

                            $update = $pdo->prepare("
                                UPDATE BatteryInventory 
                                SET Location = 'SCRAPPED'
                                WHERE BatteryID = :bid
                                  AND Location NOT IN ('SOLD','SCRAPPED','ROTATED')
                            ");
                            $update->execute([':bid' => $bid]);

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

                            header("Location: index.php?view=menu&msg=scrapped");
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
    $stmtTrucks = $pdo->query("
        SELECT Truck
        FROM Trucks
        WHERE Truck IS NOT NULL AND Truck <> ''
        ORDER BY Truck
    ");
    $stockTruckTruckList = $stmtTrucks->fetchAll(PDO::FETCH_COLUMN);

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

        if (isset($_POST['show_truck'])) {
            $stockTruckShowConfirmClear = false;
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck.";
            } else {
                $stockTruckRows          = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery  = true;
            }
        }
        elseif (isset($_POST['transfer_to_truck'])) {
            $stockTruckDidShowQuery = true;
            $stockTruckShowConfirmClear = false;

            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck before transferring.";
            } elseif ($stockTruckTransferBatteryId === '') {
                $stockTruckError = "Please enter a BatteryID to transfer.";
            } else {
                $bid = $stockTruckTransferBatteryId;

                try {
                    $sql = "
                        SELECT 
                            BatteryInventory.BatteryID,
                            BatteryInventory.Battery,
                            BatteryInventory.DateCode,
                            BatteryInventory.Location
                        FROM BatteryInventory
                        WHERE BatteryInventory.BatteryID = :bid
                          AND BatteryInventory.Location NOT IN ('SOLD','SCRAPPED','ROTATED')
                    ";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([':bid' => $bid]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$row) {
                        $stockTruckError = "Battery not found, or it is SOLD/SCRAPPED/ROTATED.";
                    } elseif ($row['Location'] === $stockTruckSelectedTruck) {
                        $stockTruckError = "Battery is already on truck " . $stockTruckSelectedTruck . ".";
                    } else {
                        $fromLoc = $row['Location'];
                        $toLoc   = $stockTruckSelectedTruck;
                        $battery = $row['Battery'];
                        $dateCode= $row['DateCode'];

                        $pdo->beginTransaction();

                        $check = $pdo->prepare("
                            SELECT Location
                            FROM BatteryInventory
                            WHERE BatteryID = :bid
                              AND Location NOT IN ('SOLD','SCRAPPED','ROTATED')
                        ");
                        $check->execute([':bid' => $bid]);
                        $current = $check->fetch(PDO::FETCH_ASSOC);

                        if (!$current || $current['Location'] !== $fromLoc) {
                            $pdo->rollBack();
                            $stockTruckError = "Battery location changed or is now SOLD/SCRAPPED/ROTATED. Refresh and try again.";
                        } else {
                            $now = date('Y-m-d H:i:s');

                            $update = $pdo->prepare("
                                UPDATE BatteryInventory
                                SET Location = :toLoc
                                WHERE BatteryID = :bid
                            ");
                            $update->execute([
                                ':toLoc' => $toLoc,
                                ':bid'   => $bid
                            ]);

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
                            $stockTruckTransferBatteryId = "";
                        }
                    }

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $stockTruckError = "Error transferring battery to truck: " . $e->getMessage();
                }
            }

            if ($stockTruckSelectedTruck !== '') {
                $stockTruckRows         = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery = true;
            }
        }
        elseif (isset($_POST['start_clear'])) {
            if ($stockTruckSelectedTruck === '') {
                $stockTruckError = "Please select a truck before clearing.";
            } else {
                $stockTruckRows             = $runStockQuery($pdo, $stockTruckSelectedTruck);
                $stockTruckDidShowQuery     = true;
                $stockTruckShowConfirmClear = true;
            }
        }
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
                        $updInv = $pdo->prepare("
                            UPDATE BatteryInventory
                            SET Location = :shop
                            WHERE Location = :truck
                        ");
                        $updInv->execute([
                            ':shop'  => $stockTruckSelectedShop,
                            ':truck' => $stockTruckSelectedTruck
                        ]);

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
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Browns Towing – Battery Inventory</title>
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
        select, input[type="text"], textarea, input[type="number"] {
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
            flex-wrap: wrap;
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
            color:#991b1b;
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
        .flex-row-between {
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:8px;
            flex-wrap:wrap;
        }
        .top-bar-btn {
            max-width:200px;
        }
        .btn-small {
            padding: 4px 8px;
            font-size: 12px;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($view === 'inventory'): ?>

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
                    <a class="btn btn-secondary" href="battery_inventory.php?view=inventory">Clear</a>
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

        <div class="card">
            <div class="text-center mt-10">
                <?php if ($isDispatchOnly): ?>
                    <a class="btn btn-secondary top-bar-btn" href="index.php?logout=1">
                        Logout
                    </a>
                <?php else: ?>
                    <a class="btn btn-secondary top-bar-btn" href="index.php?view=menu">
                        Back to Battery Menu
                    </a>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($view === 'sell'): ?>

        <h2>Sell a Battery</h2>

        <?php if (!empty($sellError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($sellError) ?>
            </div>
        <?php endif; ?>

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

        <div class="card">
            <div class="text-center mt-10">
                <a class="btn btn-secondary top-bar-btn" href="index.php?view=menu">Back to Battery Menu</a>
            </div>
        </div>

    <?php elseif ($view === 'transfer'): ?>

        <h2>Transfer a Battery</h2>

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

        <div class="card">
            <form method="post">
                <label class="label-block">BatteryID</label>
                <input type="text" name="battery_id"
                       value="<?= htmlspecialchars($transferBatteryId ?? '') ?>"
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

        <div class="card">
            <div class="text-center mt-10">
                <a class="btn btn-secondary top-bar-btn" href="index.php?view=menu">Back to Battery Menu</a>
            </div>
        </div>

    <?php elseif ($view === 'scrap'): ?>

        <h2>Scrap a Battery</h2>

        <?php if (!empty($scrapError)): ?>
            <div class="card msg msg-error">
                <?= htmlspecialchars($scrapError) ?>
            </div>
        <?php endif; ?>

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

        <div class="card">
            <div class="text-center mt-10">
                <a class="btn btn-secondary top-bar-btn" href="index.php?view=menu">Back to Battery Menu</a>
            </div>
        </div>

    <?php elseif ($view === 'stocktruck'): ?>

        <h2>Stock Truck</h2>

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

        <?php if ($stockTruckDidShowQuery && $stockTruckSelectedTruck !== ''): ?>
            <div class="card">
                <h3 style="margin-top:0;">Transfer Battery to This Truck</h3>
                <form method="post">
                    <input type="hidden" name="truck" value="<?= htmlspecialchars($stockTruckSelectedTruck) ?>">

                    <label class="label-block">BatteryID</label>
                    <input type="text" name="battery_id"
                           value="<?= htmlspecialchars($stockTruckTransferBatteryId ?? '') ?>"
                           placeholder="Enter BatteryID">

                    <button type="submit" name="transfer_to_truck" class="btn mt-10">
                        Transfer Battery to <?= htmlspecialchars($stockTruckSelectedTruck) ?>
                    </button>
                </form>
                <p class="mt-6 small-note">
                    Uses the same transfer logic as the Transfer Battery screen, but sends the battery directly to this truck.
                </p>
            </div>
        <?php endif; ?>

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

        <div class="card">
            <div class="text-center mt-10">
                <a class="btn btn-secondary top-bar-btn" href="index.php?view=menu">Back to Battery Menu</a>
            </div>
        </div>

    <?php elseif ($view === 'history'): ?>

        <h2>History</h2>

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

        <div class="card">
            <div class="text-center mt-10">
                <a class="btn btn-secondary top-bar-btn" href="index.php?view=menu">Back to Battery Menu</a>
            </div>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="index.php?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>
</body>
</html>
