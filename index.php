<?php
// === CONFIG: put your RDS info here ===
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

// Pull detailed rows, then we'll group in PHP
$sql = "
    SELECT 
        Battery.BatteryID,
        Battery.Battery,
        Battery.DateCode,
        Inventory.Location,
        Inventory.LastUpdate
    FROM Battery
    JOIN Inventory 
        ON Battery.BatteryID = Inventory.BatteryID
    ORDER BY Battery.Battery, Inventory.Location, Battery.DateCode
";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by BatteryID + Location
$groups = [];
foreach ($rows as $r) {
    $key = ($r['BatteryID'] ?? '') . '|' . ($r['Location'] ?? '');
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'BatteryID' => $r['BatteryID'] ?? '',
            'Battery'   => $r['Battery'] ?? '',
            'Location'  => $r['Location'] ?? '',
            'rows'      => []
        ];
    }
    $groups[$key]['rows'][] = $r;
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Battery Inventory Viewer</title>
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
            margin: 10px 0 15px;
        }
        .table-container { 
            max-height: 75vh; 
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
        }
        th { 
            background: #f3f4f6; 
            position: sticky; 
            top: 0; 
            z-index: 2;
            text-align: left;
        }
        .group-row { 
            background: #fff; 
            cursor: pointer; 
        }
        .group-row:hover { 
            background: #eef2ff; 
        }
        .group-main {
            display: flex;
            flex-direction: column;
        }
        .group-main span:first-child {
            font-weight: 600;
        }
        .group-sub {
            font-size: 12px;
            color: #6b7280;
        }
        .count-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background: #e5e7eb;
            font-size: 12px;
        }
        .detail-row { 
            display: none; 
            background: #f9fafb; 
        }
        .detail-inner-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .detail-inner-table th,
        .detail-inner-table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 4px 6px;
        }
        .toggle-indicator {
            font-size: 18px;
            width: 20px;
            text-align: center;
        }
        @media (max-width: 600px) {
            th, td { padding: 6px; font-size: 13px; }
        }
    </style>
</head>
<body>
    <h2>Battery Inventory</h2>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="width:40px;"></th>
                    <th>Battery</th>
                    <th>Location</th>
                    <th>Count</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $i = 0;
            foreach ($groups as $key => $g):
                $groupId = "group_" . $i;
                $count = count($g['rows']);
                // optional: latest update in this group
                $latestUpdate = '';
                foreach ($g['rows'] as $row) {
                    if (!empty($row['LastUpdate']) && $row['LastUpdate'] > $latestUpdate) {
                        $latestUpdate = $row['LastUpdate'];
                    }
                }
            ?>
                <!-- Summary row (clickable) -->
                <tr class="group-row" data-group="<?= htmlspecialchars($groupId) ?>">
                    <td class="toggle-indicator">▶</td>
                    <td>
                        <div class="group-main">
                            <span><?= htmlspecialchars($g['Battery']) ?></span>
                            <span class="group-sub">ID: <?= htmlspecialchars($g['BatteryID']) ?></span>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($g['Location']) ?></td>
                    <td>
                        <span class="count-pill">
                            <?= $count ?> record<?= $count === 1 ? '' : 's' ?>
                            <?php if ($latestUpdate !== ''): ?>
                                &nbsp;· last <?= htmlspecialchars($latestUpdate) ?>
                            <?php endif; ?>
                        </span>
                    </td>
                </tr>

                <!-- Detail row (hidden until clicked) -->
                <tr class="detail-row" id="<?= htmlspecialchars($groupId) ?>">
                    <td></td>
                    <td colspan="3">
                        <table class="detail-inner-table">
                            <thead>
                                <tr>
                                    <th>Date Code</th>
                                    <th>Last Update</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($g['rows'] as $row): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['DateCode'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($row['LastUpdate'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
            <?php
                $i++;
            endforeach;
            if ($i === 0):
            ?>
                <tr>
                    <td colspan="4" style="text-align:center; padding:20px; color:#6b7280;">
                        No records found.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.group-row').forEach(function (row) {
            row.addEventListener('click', function () {
                const groupId = row.getAttribute('data-group');
                const detailRow = document.getElementById(groupId);
                const indicator = row.querySelector('.toggle-indicator');

                if (!detailRow) return;

                const isHidden = detailRow.style.display === '' || detailRow.style.display === 'none';
                detailRow.style.display = isHidden ? 'table-row' : 'none';
                if (indicator) {
                    indicator.textContent = isHidden ? '▼' : '▶';
                }
            });
        });
    });
    </script>
</body>
</html>
