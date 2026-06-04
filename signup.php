<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

$msg = "";
$status = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username_id = strtolower(trim($_POST['username_id']));
    $pwd = $_POST['password'];

    if (empty($username_id)) {
        $msg = "Please enter a valid Username ID.";
        $status = "error";
    } elseif (strlen($pwd) < 6) {
        $msg = "Password must be at least 6 characters long.";
        $status = "error";
    } else {
        $stmt = $conn->prepare("SELECT id, is_admin FROM engineers WHERE username_id = ?");
        $stmt->bind_param("s", $username_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            $hashed_pwd = password_hash($pwd, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE engineers SET password = ? WHERE username_id = ?");
            $update_stmt->bind_param("ss", $hashed_pwd, $username_id);
            
            if ($update_stmt->execute()) {
                $msg = "Account activated successfully! Redirecting to login...";
                $status = "success";
                header("refresh:3;url=login.php");
            } else {
                $msg = "System error, activation failed.";
                $status = "error";
            }
            $update_stmt->close();
        } else {
            $msg = "Your Username ID is not in the system. Please contact Admin to register your ID first.";
            $status = "error";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Activate Account - Timesheet System</title>
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
    <h2 style="text-align:center; margin-bottom:20px;">Engineer Activation</h2>
    
    <?php if(!empty($msg)): ?>
        <div style="color: <?php echo $status=='success'?'green':'red'; ?>; text-align:center; font-weight:bold; margin-bottom:15px; font-size:14px;">
            <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <label>Username ID:</label>
        <div class="input-group" style="margin-bottom: 10px;">
            <input type="text" name="username_id" placeholder="e.g. xx.xx" required value="<?php echo isset($_POST['username_id']) ? htmlspecialchars($_POST['username_id']) : ''; ?>">
            <span class="suffix">@jos.com.my</span>
        </div>
        
        <label>Set Password:</label>
        <input type="password" name="password" placeholder="Min 6 characters" required>
        
        <button type="submit">Activate Account</button>
    </form>
    
    <div class="link-text">
        Already activated? <a href="login.php">Login here</a>
    </div>
</div>
</body>
</html>
