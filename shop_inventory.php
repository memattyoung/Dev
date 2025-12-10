<?php
// ===== CONFIG =====
$dbHost = getenv('BROWNS_DB_HOST');
$dbName = getenv('BROWNS_DB_NAME');
$dbUser = getenv('BROWNS_DB_USER');
$dbPass = getenv('BROWNS_DB_PASS');

if (!$dbHost || !$dbName || !$dbUser || !$dbPass) {
    die("Database environment variables are not set.");
}

date_default_timezone_set('America/New_York');
session_start();

// ===== LOGOUT =====
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT =====
$timeoutSeconds = 300;
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {

        $_SESSION = [];
        session_destroy();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// ===== LOGIN GATE =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Enter AAA & Password.";
        } else {
            try {
                $pdoLogin = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                                    $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "SELECT AAA, FirstName, LastName, Manager
                           FROM Employee
                           WHERE AAA = :aaa AND Password = :pwd";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([':aaa' => $aaa, ':pwd' => $pwd]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $now = date('Y-m-d H:i:s');
                    $_SESSION['logged_in'] = true;
                    $_SESSION['empAAA'] = $emp['AAA'];
                    $_SESSION['empFirst'] = $emp['FirstName'];
                    $_SESSION['empLast'] = $emp['LastName'];
                    $_SESSION['empName'] = $emp['FirstName'] . " " . $emp['LastName'];
                    $_SESSION['isManager'] = ((int)$emp['Manager'] === 1);
                    $_SESSION['last_activity'] = time();

                    $ins = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                        (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type,
                         Invoice, Battery, DateCode, Reason, Location, Computer,
                         LastUpdate, StockType, Quantity)
                        VALUES (:ID, :EMP, '', '', '', 'Log On', '', '', '',
                                '', 'MOBILE', 'MOBILE', :TS, 'BATTERY', 1)
                    ");
                    $ins->execute([
                        ':ID' => $emp['AAA'],
                        ':EMP' => $_SESSION['empName'],
                        ':TS' => $now
                    ]);

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }
            } catch (Exception $e) {
                $error = "Login error: " . $e->getMessage();
            }
        }
    }
    ?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Browns Towing Login</title>
</head>
<body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
<h2 style="text-align:center;">Manager Login</h2>

<?php if ($error): ?>
<p style="color:red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="post">
    <label>AAA:</label>
    <input type="text" name="aaa" style="width:100%;">
    <label>Password:</label>
    <input type="password" name="password" style="width:100%;">
    <button type="submit" style="width:100%;margin-top:10px;">Login</button>
</form>
</body>
</html>
<?php exit; } // END LOGIN GATE

// ===== CONNECT FOR MAIN SITE =====
$pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$empAAA = $_SESSION['empAAA'];
$empName = $_SESSION['empName'];
$isManager = $_SESSION['isManager'];

// ===== ROUTING =====
$view = $_GET['view'] ?? ($isManager ? 'manager' : 'menu');
$msg  = $_GET['msg'] ?? '';
$invRows = $allLocations = $allBatteries = [];

// ===== BATTERY INVENTORY VIEW =====
if ($view === 'inventory') {
    $selectedLocation = trim($_GET['loc'] ?? '');
    $selectedBattery  = trim($_GET['bat'] ?? '');

    $q = "SELECT Battery, Location
          FROM BatteryInventory
          WHERE Location NOT IN ('SOLD','SCRAPPED','ROTATED')
          GROUP BY Battery, Location
          ORDER BY Battery, Location";
    $result = $pdo->query($q)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($result as $r) {
        if (!in_array($r['Location'], $allLocations)) $allLocations[] = $r['Location'];
        if (!in_array($r['Battery'], $allBatteries)) $allBatteries[] = $r['Battery'];
    }

    $sql = "SELECT Battery, COUNT(*) Quantity, Location
            FROM BatteryInventory
            WHERE Location NOT IN ('SOLD','SCRAPPED','ROTATED')";
    $params = [];
    if ($selectedLocation) {
        $sql .= " AND Location = :loc";
        $params[':loc'] = $selectedLocation;
    }
    if ($selectedBattery) {
        $sql .= " AND Battery = :bat";
        $params[':bat'] = $selectedBattery;
    }
    $sql .= " GROUP BY Battery, Location ORDER BY Battery, Location";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Browns Towing</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body {font-family:sans-serif;background:#f9fafb;margin:0;padding:10px;}
.btn{background:#2563eb;color:#fff;border-radius:6px;padding:10px;text-align:center;text-decoration:none;display:inline-block;}
.btn-secondary{background:#4b5563;}
.card{background:#fff;padding:10px;border-radius:8px;margin-top:10px;}
.menu-grid{display:grid;gap:10px;margin-top:20px;}
@media(min-width:600px){.menu-grid{grid-template-columns:repeat(4,1fr);}}
.table-container{max-height:65vh;overflow-y:auto;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th,td{border:1px solid #ddd;padding:6px;}
th{background:#eee;position:sticky;top:0;}
</style>
</head>
<body>
<div class="container">

<?php if ($msg === 'sold'): ?>
<p class="card" style="background:#dcfce7;color:#166534;">
    Battery sold successfully
</p>
<?php endif; ?>

<?php if ($view === 'menu'): ?>
<h2 style="text-align:center;">Battery Menu</h2>
<div class="menu-grid">
    <a class="btn" href="?view=inventory">Inventory</a>
    <a class="btn" href="?view=sell">Sell</a>
    <a class="btn" href="?view=transfer">Transfer</a>
    <a class="btn" href="?view=scrap">Scrap</a>
</div>

<div class="card" style="text-align:center;">
    <a href="?view=history" class="btn btn-secondary" style="width:100%;">History</a>
</div>
<?php endif; ?>

<?php if ($view === 'inventory'): ?>
<div class="card">
<h3>Inventory</h3>
<form method="get">
<input type="hidden" name="view" value="inventory">
<label>Location:</label>
<select name="loc">
<option value="">All</option>
<?php foreach($allLocations as $loc): ?>
<option <?= $loc==$selectedLocation?'selected':''?>><?=htmlspecialchars($loc)?></option>
<?php endforeach;?>
</select>

<label>Battery:</label>
<select name="bat">
<option value="">All</option>
<?php foreach($allBatteries as $bat): ?>
<option <?= $bat==$selectedBattery?'selected':''?>><?=htmlspecialchars($bat)?></option>
<?php endforeach;?>
</select>
<button class="btn" style="margin-top:6px;">Filter</button>
<a class="btn-secondary btn" href="?view=menu" style="margin-top:6px;">Menu</a>
</form>
</div>

<div class="card">
<div class="table-container">
<table>
<tr><th>Battery</th><th>Qty</th><th>Location</th></tr>
<?php if(!$invRows): ?>
<tr><td colspan="3" style="text-align:center;">No results</td></tr>
<?php else:
foreach($invRows as $r): ?>
<tr>
<td><?=htmlspecialchars($r['Battery'])?></td>
<td><?=htmlspecialchars($r['Quantity'])?></td>
<td><?=htmlspecialchars($r['Location'])?></td>
</tr>
<?php endforeach; endif;?>
</table>
</div>
</div>
<?php endif; ?>
