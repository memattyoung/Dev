<?php
// === CONFIG: put your RDS info here ===
$dbHost = "browns-test.cr4wimy2q8ur.us-east-2.rds.amazonaws.com";
$dbName = "Browns";
$dbUser = "memattyoung";
$dbPass = "Myoung0996!";
$appPassword = "GoldFish"; // simple app-level password

session_start();

// ---------- LOGIN GATE ----------
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

// ---------- USER IS LOGGED IN: CONNECT DB ----------
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// Which page are we on? (menu, inventory, sell, add, remove)
$page = $_GET['page'] ?? 'menu';

// ======================================================
// ===============  SIMPLE MENU PAGE  ===================
// ======================================================
if ($page === 'menu') {
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Battery App Menu</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {
                font-family: system-ui, -apple-system, sans-serif;
                margin: 0;
                padding: 20px;
                background: #f3f4f6;
            }
            .container {
                max-width: 500px;
                margin: 40px auto;
                background: #ffffff;
                padding: 20px;
                border-radius: 12px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.1);
                text-align: center;
            }
            h2 {
                margin-top: 0;
            }
            .menu-btn {
                display: block;
                width: 100%;
                padding: 14px;
                margin: 10px 0;
                font-size: 16px;
                border-radius: 8px;
                border: none;
                cursor: pointer;
            }
            .inventory {
                background: #2563eb;
                color: white;
            }
            .secondary {
                background: #e5e7eb;
                color: #111827;
            }
            .menu-btn:active {
                transform: scale(0.98);
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h2>Battery Management</h2>
            <p>Select an option:</p>

            <a href="?page=inventory">
                <button class="menu-btn inventory">Inventory</button>
            </a>

            <a href="?page=sell">
                <button class="menu-btn secondary">Sell Battery</button>
            </a>

            <a href="?page=add">
                <button class="menu-btn secondary">Add Battery</button>
            </a>

            <a href="?page=remove">
                <button class="menu-btn secondary">Remove Battery</button>
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ======================================================
// ===============  INVENTORY PAGE  =====================
// ======================================================
if ($page === 'inventory') {

    // --- Read filters from GET ---
    $filterLocation = $_GET['location'] ?? '';
    $filterBattery  = $_GET['battery'] ?? '';

    // Base SQL: aggregate by Battery + Location
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

    $sql .= "
        GROUP BY Battery.Battery, Inventory.Location
        ORDER BY Battery.Battery, Inventory.Location
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build dropdown options from result rows
    $locations = [];
    $batteries = [];
    foreach ($rows as $r) {
        if (!empty($r['Location'])) $locations[$r['Location']] = true;
        if (!empty($r['Battery']))  $batteries[$r['Battery']] = true;
    }
    $locations = array_keys($locations);
    $batteries = array_keys($batteries);
    sort($locations);
    sort($batteries);

    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Battery Inventory</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { 
                font-family: system-ui, -apple-system, sans-serif; 
                margin: 0; 
                padding: 10px; 
                background: #f3f4f6;
            }
            .top-bar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 10px;
            }
            .top-bar a {
                text-decoration: none;
                color: #2563eb;
                font-size: 14px;
            }
            h2 { text-align: center; margin: 10px 0; }

            .filter-form {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-bottom: 10px;
                align-items: center;
            }
            .filter-form label {
                font-size: 14px;
            }
            .filter-form select {
                padding: 6px;
                font-size: 14px;
            }
            .filter-form button {
                padding: 6px 12px;
                font-size: 14px;
                border-radius: 6px;
                border: none;
                background: #2563eb;
                color: white;
            }

            .table-container { 
                max-height: 70vh; 
                overflow-y: auto; 
                background: #ffffff;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            table { 
                width: 100%; 
                border-collapse: collapse; 
                font-size: 14px; 
            }
            th, td { 
                border: 1px solid #ddd; 
                padding: 6px; 
                text-align: left;
            }
            th { 
                background: #e5e7eb; 
                position: sticky; 
                top: 0; 
                z-index: 1;
            }
        </style>
    </head>
    <body>
        <div class="top-bar">
            <a href="?page=menu">&larr; Back to Menu</a>
            <span style="font-size:14px; color:#6b7280;">Inventory View</span>
            <span></span>
        </div>

        <h2>Battery Inventory</h2>

        <!-- Filters -->
        <form method="get" class="filter-form">
            <input type="hidden" name="page" value="inventory">

            <label>
                Location:
                <select name="location">
                    <option value="">All</option>
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
                    <option value="">All</option>
                    <?php foreach ($batteries as $bat): ?>
                        <option value="<?= htmlspecialchars($bat) ?>" 
                            <?= $filterBattery === $bat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($bat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <button type="submit">Apply</button>
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
    </body>
    </html>
    <?php
    exit;
}

// ======================================================
// ========== PLACEHOLDER PAGES FOR OTHER MENU =========
// ======================================================

function render_placeholder($title, $label) {
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($title) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {
                font-family: system-ui, -apple-system, sans-serif;
                margin: 0;
                padding: 20px;
                background: #f3f4f6;
            }
            .container {
                max-width: 500px;
                margin: 40px auto;
                background: #ffffff;
                padding: 20px;
                border-radius: 12px;
                box-shadow: 0 2px 6px rgba(0,0,0,0.1);
                text-align: center;
            }
            a {
                text-decoration: none;
                color: #2563eb;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <p><a href="?page=menu">&larr; Back to Menu</a></p>
            <h2><?= htmlspecialchars($label) ?></h2>
            <p>Coming soon. We’ll build this function here later.</p>
        </div>
    </body>
    </html>
    <?php
}

// Sell Battery
if ($page === 'sell') {
    render_placeholder('Sell Battery', 'Sell Battery');
    exit;
}

// Add Battery
if ($page === 'add') {
    render_placeholder('Add Battery', 'Add Battery');
    exit;
}

// Remove Battery
if ($page === 'remove') {
    render_placeholder('Remove Battery', 'Remove Battery');
    exit;
}

// Fallback – unknown page
header("Location: ?page=menu");
exit;
