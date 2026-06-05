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
            $_SESSION['engineer_id']   = $row['id'];
            $_SESSION['engineer_name'] = $row['engineer_name'];
            $_SESSION['is_admin']      = $row['is_admin'];

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
        $error = "Username does not exist.";
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
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f7f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 15px; }
        .card { background: white; padding: 30px 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        h2 { font-size: 22px; margin: 0 0 20px; text-align: center; }
        label { display: block; font-weight: bold; font-size: 14px; margin-bottom: 5px; }
        input { width: 100%; padding: 13px 14px; border: 1px solid #ccc; border-radius: 4px; font-size: 15px; margin-bottom: 15px; }
        input:focus { border-color: #007bff; outline: none; }
        .btn-login { width: 100%; padding: 14px; background: #007bff; color: white; border: none; border-radius: 4px; font-size: 16px; font-weight: bold; cursor: pointer; margin-top: 5px; }
        .btn-login:hover { background: #0056b3; }
        .error-box { background: #f8d7da; color: #721c24; padding: 11px 14px; border: 1px solid #f5c6cb; border-radius: 4px; font-size: 13px; margin-bottom: 15px; text-align: center; }
        .link-text { text-align: center; margin-top: 15px; font-size: 14px; }
        .link-text a { color: #007bff; text-decoration: none; }
    </style>
</head>
<body>
<div class="card">
    <h2>Timesheet Login</h2>

    <?php if (!empty($error)): ?>
        <div class="error-box">⚠️ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST">
        <label>Username:</label>
        <input type="text" name="username_id" placeholder="e.g. john.doe" required
               value="<?php echo isset($_POST['username_id']) ? htmlspecialchars($_POST['username_id']) : ''; ?>">

        <label>Password:</label>
        <input type="password" name="password" placeholder="Password" required>

        <button type="submit" class="btn-login">Login</button>
    </form>

    <div class="link-text">
        Don't have an account? <a href="signup.php">Sign up now</a>
    </div>
</div>
</body>
</html>