<?php
// === CONFIG: put your RDS info here ===
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Simple "password" for managers to access page (not fancy, but easy)
$appPassword = "GoldFish";

// --- Basic login gate ---
session_start();
if (!isset($_SESSION['logged_in'])) {
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
        <?php if (!empty($error)) echo "<p style='color:red;'>$error</p>"; ?>
        <form method="post">
            <label>Password:</label><br>
            <input type="password" name="password" style="width:100%; padding:8px; margin:8px 0;">
            <button type="submit" style="width:100%; padding:10px;">Enter</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// --- If we're here, user is logged in ---

// Connect to DB
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// Determine which page we are on
$page = $_GET['page'] ?? 'menu';

// Small helper: header/footer HTML
function render_header($title = "Browns Battery") {
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($title) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { font-family: system-ui, -apple-system, sans-serif; margin: 0; padding: 10px; }
            h2 { text-align: center; }
            .btn {
                display: block;
                width: 100%;
                padding: 12px;
                margin: 8px 0;
                text-align: center;
                background: #2563eb;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                border: none;
                font-size: 16px;
            }
            .btn-secondary {
                background: #6b7280;
            }
            .container {
                max-width: 800px;
                margin: 0 auto;
            }
            table { width: 100%; border-collapse: collapse; font-size: 14px; }
            th, td { border: 1px solid #ddd; padding: 6px; }
            th { background: #f3f4f6; position: sticky; top: 0; }
            .table-container { max-height: 70vh; overflow-y: auto; }
            label { display:block; margin-top:8px; }
            input[type="text"], select {
                width: 100%;
                padding: 8px;
                margin-top: 4px;
                box-sizing: border-box;
            }
            .message {
                margin: 10px 0;
                padding: 10px;
                border-radius: 4px;
            }
            .message-success { background:#dcfce7; color:#166534; }
            .message-error { background:#fee2e2; color:#991b1b; }
        </style>
    </head>
    <body>
    <div class="container">
    <?php
}

function render_footer() {
    ?>
    </div>
    </body>
    </html>
    <?php
}

// --- MENU PAGE ---
if ($page === 'menu') {
    render_header("Browns Battery - Menu");

    if (!empty($_GET['msg'])) {
        echo '<div class="message message-success">'.htmlspecialchars($_GET['msg']).'</div>';
    }
    ?>
    <h2>Main Menu</h2>
    <a class="btn" href="?page=inventory">Inventory</a>
    <a class="btn" href="?page=sell">Sell Battery</a>
    <a class="btn btn-secondary" href="?page=add">Add Battery</a>
    <a class="btn btn-secondary" href="?page=remove">Remove Battery</a>
    <?php
    render_footer();
    exit;
}

// --- INVENTORY PAGE ---
if ($page === 'inventory') {
    render_header("Inventory");

    // Filters
    $filterLocation = $_GET['location'] ?? '';
    $filterBattery  = $_GET['battery'] ?? '';

    // Get dropdown options from current data
    $locStmt = $pdo->query("
        SELECT DISTINCT Inventory.Location AS Location
        FROM Battery
        JOIN Inventory ON Battery.BatteryID = Inventory.BatteryID
        ORDER BY Inventory.Location
    ");
    $locations = $locStmt->fetchAll(PDO::FETCH_COLUMN);

    $batStmt = $pdo->query("
        SELECT DISTINCT Battery.Battery AS Battery
        FROM Battery
        JOIN Inventory ON Battery.BatteryID = Inventory.BatteryID
        ORDER BY Battery.Battery
    ");
    $batteries = $batStmt->fetchAll(PDO::FETCH_COLUMN);

    // Build main query
    $sql = "
        SELECT 
            Battery.Battery AS Battery,
            COUNT(Battery.Battery) AS Quantity,
            Inventory.Location AS Location
        FROM Battery
        JOIN Inventory ON Battery.BatteryID = Inventory.BatteryID
        WHERE 1=1
    ";

    $params = [];

    if ($filterLocation !== '') {
        $sql .= " AND Inventory.Location = :loc";
        $params[':loc'] = $filterLocation;
    }
    if ($filterBattery !== '') {
        $sql .= " AND Battery.Battery = :bat";
        $params[':bat'] = $filterBattery;
    }

    $sql .= " GROUP BY Battery.Battery, Inventory.Location
              ORDER BY Battery.Battery, Inventory.Location";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <h2>Inventory</h2>
    <form method="get" style="margin-bottom:10px;">
        <input type="hidden" name="page" value="inventory">
        <label>
            Location:
            <select name="location">
                <option value="">(All)</option>
                <?php foreach ($locations as $loc): ?>
                    <option value="<?= htmlspecialchars($loc) ?>"
                        <?= $filterLocation === $loc ? 'selected' : '' ?>>
                        <?= htmlspecialchars($loc) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Battery:
            <select name="battery">
                <option value="">(All)</option>
                <?php foreach ($batteries as $bat): ?>
                    <option value="<?= htmlspecialchars($bat) ?>"
                        <?= $filterBattery === $bat ? 'selected' : '' ?>>
                        <?= htmlspecialchars($bat) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn" style="margin-top:10px;">Apply Filters</button>
        <a href="?page=menu" class="btn btn-secondary">Back to Menu</a>
    </form>

    <div class="table-container">
        <table>
            <tr>
                <th>Battery</th>
                <th>Quantity</th>
                <th>Location</th>
            </tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['Battery'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Quantity'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['Location'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php
    render_footer();
    exit;
}

// --- SELL BATTERY PAGE ---
if ($page === 'sell') {
    render_header("Sell Battery");

    $step = $_POST['step'] ?? 'lookup';
    $message = '';
    $error = '';
    $foundRow = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // STEP 1: LOOKUP
        if ($step === 'lookup') {
            $batteryIdInput = trim($_POST['batteryid'] ?? '');

            if ($batteryIdInput === '') {
                $error = "Please enter a BatteryID.";
            } else {
                $sql = "
                    SELECT 
                        Battery.BatteryID,
                        Battery.Battery,
                        Battery.DateCode,
                        Inventory.Location AS Location
                    FROM Battery
                    JOIN Inventory ON Battery.BatteryID = Inventory.BatteryID
                    WHERE Battery.BatteryID = :bid
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':bid' => $batteryIdInput]);
                $foundRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$foundRow) {
                    $error = "BatteryID not found.";
                }
            }

        // STEP 2: CONFIRM SELL
        } elseif ($step === 'sell') {
            $batteryId = $_POST['BatteryID'] ?? '';
            $battery   = $_POST['Battery'] ?? '';
            $dateCode  = $_POST['DateCode'] ?? '';
            $location  = $_POST['Location'] ?? '';

            if ($batteryId === '' || $location === '') {
                $error = "Missing BatteryID or Location.";
            } else {
                try {
                    $pdo->beginTransaction();

                    // Update Inventory to SOLD
                    $upd = $pdo->prepare("
                        UPDATE Inventory
                        SET Location = 'SOLD'
                        WHERE BatteryID = :bid
                          AND Location = :loc
                        LIMIT 1
                    ");
                    $upd->execute([
                        ':bid' => $batteryId,
                        ':loc' => $location
                    ]);

                    if ($upd->rowCount() === 0) {
                        // nothing updated
                        $pdo->rollBack();
                        $error = "No matching inventory record to update (maybe already SOLD).";
                    } else {
                        // Insert into AuditLog
                        $ins = $pdo->prepare("
                            INSERT INTO AuditLog
                                (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type,
                                 Invoice, Battery, DateCode, Reason, Location, Computer)
                            VALUES
                                ('WEBUSER', 'Tuna Marie', :fromloc, 'SOLD', :bid, 'BatterySale',
                                 '', :battery, :datecode, '', :loc, 'MOBILE')
                        ");
                        $ins->execute([
                            ':fromloc' => $location,
                            ':bid'     => $batteryId,
                            ':battery' => $battery,
                            ':datecode'=> $dateCode,
                            ':loc'     => $location
                        ]);

                        $pdo->commit();
                        // Redirect back to menu with message
                        header("Location: ?page=menu&msg=" . urlencode("Battery $batteryId sold."));
                        exit;
                    }

                } catch (Exception $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = "Error selling battery: " . $ex->getMessage();
                }
            }
        }
    }

    ?>
    <h2>Sell Battery</h2>

    <?php if ($error): ?>
        <div class="message message-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php
    // If we have a found row from lookup, show confirmation screen
    if ($step === 'lookup' && isset($foundRow) && $foundRow): ?>
        <p>Please confirm you want to sell this battery:</p>
        <table>
            <tr><th>BatteryID</th><td><?= htmlspecialchars($foundRow['BatteryID']) ?></td></tr>
            <tr><th>Battery</th><td><?= htmlspecialchars($foundRow['Battery']) ?></td></tr>
            <tr><th>Date Code</th><td><?= htmlspecialchars($foundRow['DateCode']) ?></td></tr>
            <tr><th>Location</th><td><?= htmlspecialchars($foundRow['Location']) ?></td></tr>
        </table>

        <form method="post" style="margin-top:10px;">
            <input type="hidden" name="step" value="sell">
            <input type="hidden" name="BatteryID" value="<?= htmlspecialchars($foundRow['BatteryID']) ?>">
            <input type="hidden" name="Battery" value="<?= htmlspecialchars($foundRow['Battery']) ?>">
            <input type="hidden" name="DateCode" value="<?= htmlspecialchars($foundRow['DateCode']) ?>">
            <input type="hidden" name="Location" value="<?= htmlspecialchars($foundRow['Location']) ?>">

            <button type="submit" class="btn">Confirm Sell</button>
            <a href="?page=menu" class="btn btn-secondary">Cancel</a>
        </form>

    <?php else: ?>
        <!-- Lookup form -->
        <form method="post">
            <input type="hidden" name="step" value="lookup">
            <label>
                BatteryID:
                <input type="text" name="batteryid" value="<?= htmlspecialchars($_POST['batteryid'] ?? '') ?>">
            </label>
            <button type="submit" class="btn" style="margin-top:10px;">Lookup Battery</button>
            <a href="?page=menu" class="btn btn-secondary">Back to Menu</a>
        </form>
    <?php endif; ?>

    <?php
    render_footer();
    exit;
}

// --- PLACEHOLDER PAGES FOR ADD / REMOVE (for later) ---
if ($page === 'add' || $page === 'remove') {
    $title = $page === 'add' ? "Add Battery" : "Remove Battery";
    render_header($title);
    ?>
    <h2><?= htmlspecialchars($title) ?></h2>
    <p>We will build this section later.</p>
    <a href="?page=menu" class="btn btn-secondary">Back to Menu</a>
    <?php
    render_footer();
    exit;
}

// Fallback
render_header("Unknown Page");
echo "<p>Unknown page.</p><a href='?page=menu' class='btn btn-secondary'>Back to Menu</a>";
render_footer();
