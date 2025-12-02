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

// Example query – change this to your real table/query
// e.g. SELECT * FROM battery ORDER BY date_code DESC LIMIT 100
$sql = "SELECT * From Inventory";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>AWS Data Viewer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; margin: 0; padding: 10px; }
        h2 { text-align: center; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border: 1px solid #ddd; padding: 6px; }
        th { background: #f3f4f6; position: sticky; top: 0; }
        .table-container { max-height: 70vh; overflow-y: auto; }
    </style>
</head>
<body>
    <h2>Battery Table</h2>
    <div class="table-container">
        <table>
            <tr>
                <th>Battery</th>
                <th>Battery ID</th>
                <th>Date Code</th>
            </tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['battery'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['batteryid'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['date_code'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</body>
</html>
