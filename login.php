<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: login.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username_id = strtolower(trim($_POST['username_id']));
    $pwd = $_POST['password'];

    $stmt = $conn->prepare("SELECT id, engineer_name, password, is_admin FROM engineers WHERE username_id = ?");
    $stmt->bind_param("s", $username_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($pwd, $row['password'])) {
            $_SESSION['engineer_id'] = $row['id'];
            $_SESSION['engineer_name'] = $row['engineer_name'];
            $_SESSION['is_admin'] = $row['is_admin'];

            if ($row['is_admin'] == 1) {
                header("Location: admin.php");
            } else {
                header("Location: index.php");
            }
            exit;
        } else {
            $error = "Incorrect password.";
        }
    } else {
        $error = "Username ID does not exist.";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - Timesheet System</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f7f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 15px; box-sizing: border-box; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 420px; box-sizing: border-box; }
        h2 { font-size: 22px; margin-top: 0; }
        input { width: 100%; padding: 14px; margin: 8px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 16px; }
        .input-group { display: flex; align-items: center; width: 100%; }
        .input-group input { border-top-right-radius: 0; border-bottom-right-radius: 0; margin: 0; flex: 1; }
        .suffix { background: #e9ecef; border: 1px solid #ccc; border-left: none; padding: 0 12px; border-top-right-radius: 4px; border-bottom-right-radius: 4px; color: #495057; font-size: 14px; white-space: nowrap; height: 47px; box-sizing: border-box; display: flex; align-items: center; }
        button { width: 100%; padding: 14px; background: #007bff; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; font-weight: bold; margin-top: 15px; }
        button:hover { background: #0056b3; }
        .link-text { text-align: center; margin-top: 15px; font-size: 14px; }
        .link-text a { color: #007bff; text-decoration: none; }
        label { font-weight: bold; font-size: 14px; color: #333; display: block; }
    </style>
</head>
<body>
<div class="card">
    <h2 style="text-align:center; margin-bottom:20px;">Timesheet Login</h2>
    <?php if(!empty($error)): ?>
        <div style="background:#f8d7da; color:#721c24; padding:12px; border:1px solid #f5c6cb; border-radius:4px; font-size:13px; margin-bottom:15px; line-height:1.5; text-align:center;">
            ⚠️ <?php echo $error; ?>
        </div>
    <?php endif; ?>
    <form method="POST">
        <label style="font-weight:bold; font-size:14px; display:block; margin-bottom:5px;">Username ID:</label>
        <div class="input-group" style="margin-bottom: 10px;">
            <input type="text" name="username_id" placeholder="e.g. xx.xx" required value="<?php echo isset($_POST['username_id']) ? htmlspecialchars($_POST['username_id']) : ''; ?>">
        </div>
        
        <label style="font-weight:bold; font-size:14px;">Password:</label>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Login</button>
    </form>
    <div class="link-text">
        Don't have an account? <a href="signup.php">Sign up now</a>
    </div>
</div>
</body>
</html>