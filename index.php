<?php
// ====== CONFIG / BOOTSTRAP ======
session_start();

// TODO: adjust these checks to match however you're storing login state
if (!isset($_SESSION['employee_id']) && !isset($_SESSION['aaa'])) {
    // Not logged in – you can redirect to login page instead if you want
    http_response_code(403);
    echo "Not authorized. Please log in.";
    exit;
}

// DB config from environment variables (Render dashboard)
$dbHost = getenv('BROWNS_DB_HOST');
$dbName = getenv('BROWNS_DB_NAME');
$dbUser = getenv('BROWNS_DB_USER');
$dbPass = getenv('BROWNS_DB_PASS');

try {
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo "Database connection failed.";
    exit;
}

// ====== FILTERS FROM QUERY STRING ======
$locationFilter = isset($_GET['loc']) ? trim($_GET['loc']) : '';
$searchFilter   = isset($_GET['q'])   ? trim($_GET['q'])   : '';

// ====== FETCH DISTINCT LOCATIONS FOR DROPDOWN ======
$locations = [];
try {
    $stmtLoc = $pdo->query("
        SELECT DISTINCT Location 
        FROM ShopInventory 
        WHERE Location IS NOT NULL AND Location <> ''
        ORDER BY Location
    ");
    $locations = $stmtLoc->fetchAll();
} catch (Exception $e) {
    // ignore, we'll just show no dropdown if it fails
}

// ====== BUILD MAIN QUERY ======
$sql = "
    SELECT ID, Make, Model, Category, Description, Quantity, Location, LastUpdate
    FROM ShopInventory
    WHERE 1 = 1
";
$params = [];

// Filter by Location (optional)
if ($locationFilter !== '') {
    $sql .= " AND Location = :loc ";
    $params[':loc'] = $locationFilter;
}

// Simple search on Make / Model / Description (optional)
if ($searchFilter !== '') {
    $sql .= " AND (Make LIKE :q OR Model LIKE :q OR Description LIKE :q) ";
    $params[':q'] = '%' . $searchFilter . '%';
}

$sql .= " ORDER BY Location, Make, Model, Description";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// For display in the search box
function h($s) {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shop Inventory</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"> <!-- mobile friendly -->

    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f3f4f6;
        }

        header {
            background-color: #111827;
            color: #f9fafb;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        header h1 {
            font-size: 18px;
            margin: 0;
        }

        .container {
            padding: 12px 16px 24px;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .filters select,
        .filters input[type="text"],
        .filters button {
            font-size: 14px;
            padding: 6px 8px;
            border-radius: 4px;
            border: 1px solid #d1d5db;
            box-sizing: border-box;
        }

        .filters button {
            background-color: #111827;
            color: #f9fafb;
            border: none;
            cursor: pointer;
        }

        .filters button:hover {
            background-color: #374151;
        }

        .pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            color: #111827;
            background-color: #e5e7eb;
            margin-left: 4px;
        }

        .cards {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }

        @media (min-width: 700px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .cards {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        .card {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 10px 12px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 4px;
        }

        .card-title {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
        }

        .card-subtitle {
            font-size: 12px;
            color: #6b7280;
        }

        .card-body {
            font-size: 12px;
            color: #374151;
            margin-top: 4px;
        }

        .card-row {
            display: flex;
            justify-content: space-between;
            margin-top: 2px;
        }

        .label {
            font-weight: 600;
        }

        .qty {
            font-weight: 700;
        }

        .qty-low {
            color: #b91c1c;
        }

        .qty-ok {
            color: #15803d;
        }

        .last-update {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 4px;
        }

        .empty-message {
            margin-top: 20px;
            font-size: 14px;
            color: #6b7280;
        }
    </style>
</head>
<body>
<header>
    <h1>Shop Inventory</h1>
    <!-- You can drop a "Back" or menu link over here -->
    <div style="font-size:12px; opacity:0.8;">
        Logged in
    </div>
</header>

<div class="container">

    <form method="get" class="filters">
        <select name="loc">
            <option value="">All Locations</option>
            <?php foreach ($locations as $locRow): 
                $loc = $locRow['Location'];
                ?>
                <option value="<?php echo h($loc); ?>"
                    <?php if ($locationFilter !== '' && $locationFilter === $loc) echo 'selected'; ?>>
                    <?php echo h($loc); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input
            type="text"
            name="q"
            placeholder="Search make/model/description..."
            value="<?php echo h($searchFilter); ?>"
        />

        <button type="submit">Filter</button>
    </form>

    <?php if (count($rows) === 0): ?>
        <div class="empty-message">
            No inventory records found for the selected filters.
        </div>
    <?php else: ?>
        <div class="cards">
            <?php foreach ($rows as $row): 
                $qty = (float)$row['Quantity'];
                $qtyClass = $qty <= 0 ? 'qty qty-low' : 'qty qty-ok';
            ?>
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">
                                <?php echo h($row['Make']); ?>
                                <?php if ($row['Model']): ?>
                                    <span class="card-subtitle"> / <?php echo h($row['Model']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="card-subtitle">
                                <?php echo h($row['Category']); ?>
                                <?php if ($row['Location']): ?>
                                    <span class="pill"><?php echo h($row['Location']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="<?php echo $qtyClass; ?>">
                            QTY: <?php echo $qty; ?>
                        </div>
                    </div>

                    <?php if ($row['Description']): ?>
                        <div class="card-body">
                            <?php echo h($row['Description']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($row['LastUpdate'])): ?>
                        <div class="last-update">
                            Last update: <?php echo h($row['LastUpdate']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
</body>
</html>
