<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_SERVER['HTTP_REFERER'])) {
    $referer = basename(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH));
    if ($referer != 'profile.php' && !empty($referer)) {
        $_SESSION['profile_return_url'] = $referer;
    }
}

$is_admin = isset($_SESSION['is_admin']) && ($_SESSION['is_admin'] == 1 || $_SESSION['is_admin'] == 2);
$back_link = isset($_SESSION['profile_return_url']) ? $_SESSION['profile_return_url'] : ($is_admin ? 'admin.php' : 'index.php');

$user_id = $_SESSION['engineer_id'];
$msg = "";
$status = "";
$stmt = $conn->prepare("SELECT username_id, engineer_name FROM engineers WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
    $new_pwd = $_POST['new_password'];
    
    if (strlen($new_pwd) < 6) {
        $msg = "New password must be at least 6 characters!";
        $status = "error";
    } else {
        $new_hashed = password_hash($new_pwd, PASSWORD_DEFAULT);
        $update_stmt = $conn->prepare("UPDATE engineers SET password = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_hashed, $user_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        $msg = "Password updated successfully!";
        $status = "success";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Profile</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; color: #333; }
        .header { display: flex; justify-content: space-between; align-items: center; background: white; padding: 15px 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); max-width: 500px; margin: 20px auto; }
        .info-group { margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .info-label { font-weight: bold; color: #666; font-size: 13px; }
        .info-value { font-size: 16px; font-weight: bold; margin-top: 5px; color: #111; }
        input { width: 100%; padding: 10px; margin: 8px 0 15px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold; width: 100%; }
        button:hover { background: #0056b3; }
        .btn-back { display: block; text-align: center; margin-top: 15px; color: #6c757d; text-decoration: none; font-size: 14px; }
    </style>
</head>
<body>

<div class="header">
    <h2>My Profile Settings</h2>
    <a href="<?php echo htmlspecialchars($back_link); ?>" style="font-weight: bold; text-decoration: none; color: #007bff;">
        ← Back to Dashboard
    </a>
</div>

<div class="card">
    <h3>Account Information</h3>
    <div class="info-group">
        <div class="info-label">Username ID:</div>
        <div class="info-value"><code><?php echo htmlspecialchars($user_info['username_id']); ?></code></div>
    </div>
    
    <div class="info-group">
        <div class="info-label">Assigned Username (Real Name):</div>
        <div class="info-value" style="color: #28a745;"><?php echo htmlspecialchars($user_info['engineer_name'] ? $user_info['engineer_name'] : 'Not assigned yet'); ?></div>
    </div>

    <h3 style="margin-top: 30px; border-top: 2px solid #f4f7f6; padding-top: 20px;">Security & Change Password</h3>
    
    <?php if(!empty($msg)): ?>
        <div style="color: <?php echo $status=='success'?'green':'red'; ?>; font-weight:bold; margin-bottom:15px; font-size:14px; text-align:center;">
            <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <label style="font-size: 13px; font-weight: bold;">New Password:</label>
        <input type="password" name="new_password" required placeholder="Min 6 characters">
        
        <button type="submit" name="change_password">Update My Password</button>
    </form>
</div>

</body>
</html> 