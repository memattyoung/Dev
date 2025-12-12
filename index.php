<?php
// ===== CONFIG =====
$dbHost = getenv('BROWNS_DB_HOST');
$dbName = getenv('BROWNS_DB_NAME');
$dbUser = getenv('BROWNS_DB_USER');
$dbPass = getenv('BROWNS_DB_PASS');

if (!$dbHost || !$dbName || !$dbUser || !$dbPass) {
    die("Database environment variables are not set. Check Render env vars.");
}

// Force PHP timezone to Eastern (handles EST/EDT automatically)
date_default_timezone_set('America/New_York');

// Start session
session_start();

// ===== LOGOUT HANDLER =====
if (isset($_GET['logout'])) {
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
    header("Location: index.php");
    exit;
}

// ===== SESSION TIMEOUT (5 MINUTES) =====
$timeoutSeconds = 300; // 5 minutes
if (isset($_SESSION['logged_in'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
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
        header("Location: index.php?msg=timeout");
        exit;
    } else {
        $_SESSION['last_activity'] = time();
    }
}

// ===== LOGIN GATE =====
if (empty($_SESSION['logged_in'])) {
    $error = "";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $aaa = strtoupper(trim($_POST['aaa'] ?? ''));
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
                    WHERE UPPER(AAA) = :aaa
                      AND Password = :pwd
                ";
                $stmtEmp = $pdoLogin->prepare($sqlEmp);
                $stmtEmp->execute([
                    ':aaa' => $aaa,
                    ':pwd' => $pwd
                ]);
                $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

                if ($emp) {
                    $empId   = $emp['AAA']; // keep stored value as-is
                    $empName = $emp['FirstName'] . " " . $emp['LastName'];
                    $now     = date('Y-m-d H:i:s'); // EST/EDT local time

                    // Insert AuditLog record for Log On
                    $insertLogin = $pdoLogin->prepare("
                        INSERT INTO AuditLog
                            (EmployeeID, Employee, FromLoc, ToLoc, BatteryID, Type, Invoice,
                             Battery, DateCode, Reason, Location, Computer, LastUpdate,
                             StockType, Quantity)
                        VALUES
                            (:empId, :empName, '', '', '', 'Log On', '', '', '', '',
                             'MOBILE', 'MOBILE', :lastUpdate, 'BATTERY', 1)
                    ");
                    $insertLogin->execute([
                        ':empId'      => $empId,
                        ':empName'    => $empName,
                        ':lastUpdate' => $now,
                    ]);

                    // Set session
                    $_SESSION['logged_in']      = true;
                    $_SESSION['empAAA']         = $empId;
                    $_SESSION['empFirst']       = $emp['FirstName'];
                    $_SESSION['empLast']        = $emp['LastName'];
                    $_SESSION['empName']        = $empName;
                    $_SESSION['empManager']     = (int)($emp['Manager'] ?? 0);
                    $_SESSION['empIsDispatch']  = (strtoupper($empId) === 'DISPATCH') ? 1 : 0;
                    $_SESSION['last_activity']  = time();

                    // Role-based redirect after login
                    if (!empty($_SESSION['empIsDispatch'])) {
                        header("Location: battery_inventory.php?view=inventory&readonly=1");
                        exit;
                    }

                    if (!empty($_SESSION['empManager'])) {
                        // Manager goes to main menu
                        header("Location: index.php");
                        exit;
                    }

                    // Regular non-manager goes straight to full battery program
                    header("Location: battery_inventory.php");
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
        <meta charset="utf-8">
        <title>Browns Towing Login</title>
        <style>
            body {
                font-family: system-ui, -apple-system, sans-serif;
                background: #f9fafb;
                margin: 0;
                padding: 10px;
            }
            .container {
                max-width: 420px;
                margin: 40px auto;
                background: #ffffff;
                border-radius: 8px;
                padding: 16px 18px 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            h2 {
                text-align: center;
                margin-top: 0;
            }
            label {
                font-weight: 600;
                display:block;
                margin-top:8px;
            }
            input[type="text"], input[type="password"] {
                width: 100%;
                padding: 8px;
                margin-top: 4px;
                border-radius: 4px;
                border: 1px solid #d1d5db;
                box-sizing: border-box;
            }
            button {
                width: 100%;
                padding: 10px;
                margin-top: 12px;
                background: #2563eb;
                border: none;
                border-radius: 6px;
                color: #fff;
                font-size: 16px;
            }
            .msg {
                margin-top: 8px;
                font-size: 14px;
            }
            .msg-error { color:#b91c1c; }
            .msg-info  { color:#92400e; }
        </style>
    </head>
    <body>
    <div class="container">
        <h2>Browns Towing Battery Program Login</h2>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'timeout'): ?>
            <p class="msg msg-info">
                Your session has expired due to inactivity. Please log in again.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p class="msg msg-error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="post">
            <label>AAA:</label>
            <input type="text" name="aaa"
                   value="<?= isset($_POST['aaa']) ? htmlspecialchars($_POST['aaa']) : '' ?>">

            <label>Password:</label>
            <input type="password" name="password">

            <button type="submit">Enter</button>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ===== IF WE REACH HERE, USER IS ALREADY LOGGED IN =====
$empAAA       = $_SESSION['empAAA']    ?? 'WEBUSER';
$empName      = $_SESSION['empName']   ?? 'User';
$isManager    = !empty($_SESSION['empManager']);
$isDispatch   = !empty($_SESSION['empIsDispatch']);

// Dispatch users: always go straight to read-only inventory
if ($isDispatch) {
    header("Location: battery_inventory.php?view=inventory&readonly=1");
    exit;
}

// Regular non-manager: always go straight to full battery program
if (!$isManager) {
    header("Location: battery_inventory.php");
    exit;
}

// Managers only: show Main Menu
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Browns Towing  Managers Menu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            margin: 0;
            padding: 10px;
            background: #f9fafb;
        }
        h1, h2 { text-align:center; }
        .container {
            max-width: 700px;
            margin: 0 auto;
        }
        .card {
            background:#ffffff;
            border-radius:8px;
            padding:16px;
            box-shadow:0 1px 3px rgba(0,0,0,0.1);
            margin-top:12px;
        }
        .btn {
            display:block;
            width:100%;
            padding:12px;
            text-align:center;
            border-radius:6px;
            border:none;
            font-size:16px;
            text-decoration:none;
            box-sizing:border-box;
        }
        .btn-primary { background:#2563eb; color:#fff; }
        .btn-secondary { background:#4b5563; color:#fff; max-width:250px; margin:0 auto; }
        .btn:active { transform:scale(0.98); }
        .small-note { font-size:13px; color:#6b7280; text-align:center; }
    </style>
</head>
<body>
<div class="container">
    <h1>Browns Towing</h1>
    <h2>Main Menu</h2>

    <div class="card">
        <p class="small-note">
            Logged in as <strong><?= htmlspecialchars($empName) ?></strong>
            (<?= htmlspecialchars($empAAA) ?>).
        </p>
    </div>

    <div class="card">
        <a href="battery_inventory.php" class="btn btn-primary">
            Battery Program
        </a>
    </div>

    <div class="card">
        <a href="shop_inventory.php" class="btn btn-primary">
            Shop Inventory
        </a>
    </div>

    <div class="card">
        <a href="?logout=1" class="btn btn-secondary">Logout</a>
    </div>
</div>
</body>
</html>
