<?php
// ===== CONFIG =====
$dbHost = getenv('BROWNS_DB_HOST');
$dbName = getenv('BROWNS_DB_NAME');
$dbUser = getenv('BROWNS_DB_USER');
$dbPass = getenv('BROWNS_DB_PASS');

// Optional sanity check while we're setting this up:
if (!$dbHost || !$dbName || !$dbUser || !$dbPass) {
    die("Database environment variables are not set. Check Render env vars.");
}

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
    // Clear session data
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
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        // Session expired
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
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=timeout");
        exit;
    } else {
        // Still active, refresh timer
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE (NO OUTPUT BEFORE THIS POINT) =====
if (!isset($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = trim($_POST['aaa'] ?? '');
        $pwd = trim($_POST['password'] ?? '');

        if ($aaa === '' || $pwd === '') {
            $error = "Please enter both AAA and Password.";
        } else {
            try {
                $dsnLogin = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
                $pdoLogin = new PDO($dsnLogin, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                $sqlEmp = "
                    SELECT AAA, FirstName, LastName, Manager
                    FROM Employee
                    WHERE AAA = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA'];
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice, Battery, DateCode, Reason, Location, Computer, LastUpdate, StockType, Quantity) 
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '', 'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']     = true;
                    $_SESSION['empAAA']        = $empId;
                    $_SESSION['empFirst']      = $emp['FirstName'];
                    $_SESSION['empLast']       = $emp['LastName'];
                    $_SESSION['empName']       = $empName;
                    // Manager flag (assuming 1 = manager, 0 = not)
                    $_SESSION['empManager']    = (int)($emp['Manager'] ?? 0);
                    $_SESSION['last_activity'] = time(); // start timeout timer

                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $error = "Invalid AAA or Password.";
                }

            } catch (Exception $e) {
                $error = "Login error: " . htmlspecialchars($e->getMessage());
            }
        }
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Browns Towing Battery Program Login</title>
    </head>
    <body style="font-family:sans-serif; max-width:400px; margin:40px auto;">
        <h2 style="text-align:center;">Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p style="color:orange;">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>AAA:</label><br>
            <input type="text" name="aaa"
                   style="width:100%; padding:8px; margin:8px 0;"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

            <label>Password:</label><br>
            <input type="password" name="password"
                   style="width:100%; padding:8px; margin:8px 0;">

            <button type="submit" style="width:100%; padding:10px;">Enter</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// ===== WE HAVE A LOGGED IN USER =====
$empAAA    = $_SESSION['empAAA']  ?? 'WEBUSER';
$empName   = $_SESSION['empName'] ?? 'Tuna Marie';
$isManager = !empty($_SESSION['empManager']);

// ===== CONNECT TO DB (for menu stats) =====
$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    die("DB connection failed: " . htmlspecialchars($e->getMessage()));
}

// ===== ROUTING / STATE (ONLY MANAGER_HOME + MENU HERE) =====
$view = $_GET['view'] ?? ($isManager ? 'manager_home' : 'menu');
$msg  = $_GET['msg']  ?? '';

$soldTodayCount = 0;

// ===== SOLD TODAY COUNT (MENU ONLY, BASED ON EST/EDT) =====
if ($view === 'menu') {
    try {
        $startToday = date('Y-m-d 00:00:00');
        $endToday   = date('Y-m-d 23:59:59');

        $stmtSold = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM AuditLog
            WHERE EmployeeID = :empId
              AND ToLoc = 'SOLD'
              AND LastUpdate >= :startToday
              AND LastUpdate <= :endToday
        ");
        $stmtSold->execute([
            ':empId'      => $empAAA,
            ':startToday' => $startToday,
            ':endToday'   => $endToday,
        ]);
        $soldTodayCount = (int)$stmtSold->fetchColumn();
    } catch (Exception $e) {
        $soldTodayCount = 0;
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Browns Towing Battery Program</title>
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
        .menu-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-top: 20px;
        }
        @media (min-width: 600px) {
            .menu-grid {
                grid-template-columns: repeat(4, 1fr);
            }
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
        .small-note {
            font-size: 12px;
            color: #6b7280;
        }
        .mt-10 { margin-top: 10px; }
        .top-bar-btn {
            max-width:200px;
        }
    </style>
</head>
<body>
<div class="container">

    <h1>Browns Towing Battery Program</h1>

    <?php if ($msg === 'sold'): ?>
        <div class="msg msg-success">
            Battery was successfully sold and logged.
        </div>
    <?php elseif ($msg === 'transferred'): ?>
        <div class="msg msg-success">
            Battery was successfully transferred and logged.
        </div>
    <?php elseif ($msg === 'scrapped'): ?>
        <div class="msg msg-success">
            Battery was successfully scrapped and logged.
        </div>
    <?php endif; ?>

    <?php if ($view === 'manager_home'): ?>

        <h2>Manager Menu</h2>

        <div class="card">
            <p class="text-center" style="font-size:13px; color:#6b7280;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong>
                (<?= htmlspecialchars($empAAA) ?>) – Manager.
            </p>
        </div>

        <div class="menu-grid">
            <a class="btn" href="shop_inventory.php">Inventory (Shop Stock)</a>
            <a class="btn" href="?view=menu">Battery Program</a>
        </div>

        <div class="card">
            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary top-bar-btn">
                    Logout
                </a>
            </div>
        </div>

    <?php /* ===== MAIN BATTERY MENU ===== */ elseif ($view === 'menu'): ?>

        <h2>Main Menu</h2>

        <div class="menu-grid">
            <a class="btn" href="battery_inventory.php?view=inventory">Inventory</a>
            <a class="btn" href="battery_inventory.php?view=sell">Sell Battery</a>
            <a class="btn" href="battery_inventory.php?view=stocktruck">Stock Truck</a>
            <a class="btn" href="battery_inventory.php?view=transfer">Transfer Battery</a>
            <a class="btn" href="battery_inventory.php?view=scrap">Scrap Battery</a>
            <a class="btn" href="battery_inventory.php?view=history">History</a>
        </div>

        <div class="card">
            <?php if ($soldTodayCount > 0): ?>
                <p class="text-center" style="font-size:13px; color:#166534; margin-top:6px;">
                    You have sold <strong><?= $soldTodayCount ?></strong>
                    battery<?= ($soldTodayCount === 1 ? '' : 'ies') ?> so far today.
                </p>
            <?php endif; ?>

            <p class="text-center small-note" style="margin-top:10px;">
                Logged in as <strong><?= htmlspecialchars($empName) ?></strong>
                (<?= htmlspecialchars($empAAA) ?>).
            </p>

            <?php if ($isManager): ?>
                <div class="text-center mt-10">
                    <a href="?view=manager_home" class="btn btn-secondary top-bar-btn">
                        Manager Menu
                    </a>
                </div>
            <?php endif; ?>

            <div class="text-center mt-10">
                <a href="?logout=1" class="btn btn-secondary top-bar-btn">
                    Logout
                </a>
            </div>
        </div>

    <?php else: ?>

        <h2>Unknown View</h2>
        <div class="card">
            <p class="text-center">
                Something went wrong. Use the menu to go back.
            </p>
            <div class="mt-10 text-center">
                <a class="btn" href="?view=menu">Back to Menu</a>
            </div>
        </div>

    <?php endif; ?>

</div>
</body>
</html>
