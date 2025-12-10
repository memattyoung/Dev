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
$distinctMakes = $pdo->query("SELECT DISTINCT Make FROM ShopInventory ORDER BY Make")->fetchAll(PDO::FETCH_COLUMN);
$distinctModels = $pdo->query("SELECT DISTINCT Model FROM ShopInventory ORDER BY Model")->fetchAll(PDO::FETCH_COLUMN);
$distinctLocs = $pdo->query("SELECT DISTINCT Location FROM ShopInventory ORDER BY Location")->fetchAll(PDO::FETCH_COLUMN);
$distinctCats = $pdo->query("SELECT DISTINCT Category FROM ShopInventory ORDER BY Category")->fetchAll(PDO::FETCH_COLUMN);

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
        select, input[type="text"] {
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
            <!-- Back to Manager Menu ONLY visible to managers (and this page is manager-only anyway) -->
            <a href="index.php?view=manager_home" class="btn btn-secondary">
                Back to Manager Menu
            </a>
        </div>
    </div>

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
            This view is read-only and is meant for quick checks of shop equipment, parts, and supplies.
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
