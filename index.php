<?php
// === CONFIG: RDS info ===
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";

// Simple "password" for managers to access page
$appPassword = "GoldFish";

session_start();

// --- Basic login gate ---
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
        <style>
            body {
                font-family: system-ui, -apple-system, sans-serif;
                max-width: 400px;
                margin: 40px auto;
                padding: 0 10px;
            }
            input, button {
                font-size: 16px;
            }
        </style>
    </head>
    <body>
        <h2>Enter Password Tuna Marie</h2>
        <?php if (!empty($error)) echo "<p style='color:red;'>" . htmlspecialchars($error) . "</p>"; ?>
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

// --- Optional filter (by Location) ---
$locationFilter = isset($_GET['location']) ? trim($_GET['location']) : '';

// Base SQL with JOIN
$sql = "
    SELECT 
        Battery.BatteryID,
        Battery.Battery,
        Battery.DateCode,
        Inventory.Location
    FROM Battery
    JOIN Inventory 
        ON Battery.BatteryID = Inventory.BatteryID
";

$params = [];

// If user typed a location, add WHERE
if ($locationFilter !== '') {
    $sql .= " WHERE Inventory.Location = :loc";
    $params[':loc'] = $locationFilter;
}

// Order results (optional)
$sql .= " ORDER BY Battery.BatteryID";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Battery / Inventory Viewer</title>
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
            margin: 10px 0 5px;
        }
        .filter-box {
            max-width: 600px;
            margin: 0 auto 10px;
            background: #ffffff;
            padding: 8px 10px;
            border-radius: 6px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.06);
        }
        .filter-box form {
            display: flex;
            gap: 6px;
        }
        .filter-box input[type="text"] {
            flex: 1;
            padding: 6px;
            font-size: 14px;
        }
        .filter-box button {
            padding: 6px 10px;
            font-size: 14px;
        }
        .table-container {
            max-width: 100%;
            margin: 0 auto;
            max-height: 75vh;
            overflow: auto;
            background: #ffffff;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 400px;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 6px 8px;
            text-align: left;
            word-break: break-word;
        }
        th {
            background: #f3f4f6;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .count {
            text-align: center;
            margin: 6px 0 10px;
            font-size: 13px;
            color: #4b5563;
        }
    </style>
</head>
<body>
    <h2>Battery Table</h2>

    <div class="filter-box">
        <form method="get">
            <label style="font-size:13px; display:flex; flex-direction:column; flex:1;">
                Location filter:
                <input 
                    type="text" 
                    name="location" 
                    value="<?= htmlspecialchars($locationFilter) ?>" 
                    placeholder="Type a location (exact match)">
            </label>
            <button type="submit">Apply</button>
        </form>
    </div>

    <div class="count">
        Showing <?= count($rows) ?> row(s)
        <?php if ($locationFilter !== ''): ?>
            for location "<strong><?= htmlspecialchars($locationFilter) ?></strong>"
        <?php endif; ?>
    </div>

    <div class="table-container">
        <table>
            <tr>
                <th>BatteryID</th>
                <th>Battery</th>
                <th>DateCode</th>
                <th>Location</th>
            </tr>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="4" style="text-align:center; color:#6b7280;">
                        No data found.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['BatteryID'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['Battery'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['DateCode'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['Location'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>
</body>
</html>
