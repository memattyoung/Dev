<?php
// === CONFIG: RDS info ===
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Simple "password" for managers to access page
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

// Read filters from GET
$selectedBattery  = isset($_GET['battery'])  ? trim($_GET['battery'])  : '';
$selectedLocation = isset($_GET['location']) ? trim($_GET['location']) : '';

// --- Get dropdown options (all valid Batteries and Locations from the join) ---

// Distinct Batteries
$optBatterySql = "
    SELECT DISTINCT Battery.Battery AS Battery
    FROM Battery
    JOIN Inventory ON Battery.BatteryID = Inventory.BatteryID
    ORDER BY Battery.Battery
";
$optBatteryStmt = $pdo->query($optBatterySql);
$batteryOptions = $optBatteryStmt->fetchAll(PDO::FETCH_COLUMN);

// Distinct Locations
$optLocationSql = "
    SELECT DISTINCT Inventory.Location AS Location
    FROM Battery
    JOIN Inventory ON Battery.BatteryID = Inventory.BatteryID
    ORDER BY Inventory.Location
";
$optLocationStmt = $pdo->query($optLocationSql);
$locationOptions = $optLocationStmt->fetchAll(PDO::FETCH_COLUMN);

// --- Main aggregated query (Battery, Quantity, Location) with optional filters ---

$sql = "
    SELECT 
        Battery.Battery AS Battery,
        COUNT(Battery.Battery) AS Quantity,
        Inventory.Location AS Location
    FROM Battery
    JOIN Inventory ON Battery.BatteryID = Inventory.BatteryID
    WHERE 1 = 1
";

$params = [];

// Apply filters if set
if ($selectedBattery !== '') {
    $sql .= " AND Battery.Battery = :battery";
    $params[':battery'] = $selectedBattery;
}

if ($selectedLocation !== '') {
    $sql .= " AND Inventory.Location = :location";
    $params[':location'] = $selectedLocation;
}

$sql .= "
    GROUP BY Battery.Battery, Inventory.Location
    ORDER BY Battery.Battery, Inventory.Location
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Battery Inventory Summary</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { 
            font-family: system-ui, -apple-system, sans-serif; 
            margin: 0; 
            padding: 10px; 
            background: #f9fafb;
        }
        h2 { 
            text-align: center; 
            margin: 10px 0 10px;
        }
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 10px auto 15px;
            max-width: 600px;
            align-items: center;
            justify-content: center;
        }
        .filters label {
            font-size: 13px;
            display: block;
            margin-bottom: 3px;
        }
        .filters select {
            padding: 6px;
            min-width: 140px;
            font-size: 14px;
        }
        .filters button {
            padding: 8px 14px;
            font-size: 14px;
            border: none;
            background: #2563eb;
            color: white;
            border-radius: 4px;
            cursor: pointer;
        }
        .filters button:hover {
            background: #1d4ed8;
        }
        .table-container { 
            max-height: 70vh; 
            overflow-y: auto; 
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            font-size: 14px; 
        }
        th, td { 
            border-bottom: 1px solid #e5e7eb; 
            padding: 8px; 
            text-align: left;
        }
        th { 
            background: #f3f4f6; 
            position: sticky; 
            top: 0; 
            z-index: 2;
        }
        .summary {
            max-width: 600px;
            margin: 0 auto 8px;
            font-size: 13px;
            color: #6b7280;
            text-align: right;
        }
        @media (max-width: 600px) {
            th, td { padding: 6px; font-size: 13px; }
            .filters { flex-direction: column; align-items: stretch; }
            .filters select, .filters button { width: 100%; }
            .summary { text-align: left; padding: 0 4px; }
        }
    </style>
</head>
<body>
    <h2>Battery Inventory Summary</h2>

    <!-- Filters -->
    <form method="get" class="filters">
        <div>
            <label for="battery">Battery</label>
            <select name="battery" id="battery">
                <option value="">All Batteries</option>
                <?php foreach ($batteryOptions as $b): ?>
                    <option value="<?= htmlspecialchars($b) ?>"
                        <?= ($b === $selectedBattery) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="location">Location</label>
            <select name="location" id="location">
                <option value="">All Locations</option>
                <?php foreach ($locationOptions as $loc): ?>
                    <option value="<?= htmlspecialchars($loc) ?>"
                        <?= ($loc === $selectedLocation) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($loc) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>&nbsp;</label>
            <button type="submit">Apply Filters</button>
        </div>
    </form>

    <div class="summary">
        <?= count($rows) ?> row(s) returned
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Battery</th>
                    <th>Quantity</th>
                    <th>Location</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) === 0): ?>
                    <tr>
                        <td colspan="3" style="text-align:center; padding:20px; color:#6b7280;">
                            No records found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['Battery'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['Quantity'] ?? '') ?></td>
                            <td><?= htmlspecialchars($r['Location'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
