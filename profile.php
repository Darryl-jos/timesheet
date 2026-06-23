<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['engineer_id'];
$is_admin = isset($_SESSION['is_admin']) ? intval($_SESSION['is_admin']) : 0;

$from_source = isset($_GET['from']) ? $_GET['from'] : '';

if ($from_source === 'admin') {
    $back_url = 'admin.php';
} elseif ($from_source === 'index') {
    $back_url = 'index.php';
} else {
    if ($is_admin === 1) {
        $back_url = 'admin.php';
        $from_source = 'admin';
    } else {
        $back_url = 'index.php';
        $from_source = 'index';
    }
}

$msg = "";
$status = "";
$stmt = $conn->prepare("SELECT username_id, engineer_name FROM engineers WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
    $old_pwd = $_POST['old_password'];
    $new_pwd = $_POST['new_password'];
    
    if (strlen($new_pwd) < 6) {
        $msg = "New password must be at least 6 characters!";
        $status = "error";
    } else {
        $check_stmt = $conn->prepare("SELECT password FROM engineers WHERE id = ?");
        $check_stmt->bind_param("i", $user_id);
        $check_stmt->execute();
        $pwd_row = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if (password_verify($old_pwd, $pwd_row['password'])) {
            $new_hashed = password_hash($new_pwd, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE engineers SET password = ? WHERE id = ?");
            $upd->bind_param("si", $new_hashed, $user_id);
            if ($upd->execute()) {
                $msg = "Password updated successfully!";
                $status = "success";
            } else {
                $msg = "Error updating password.";
                $status = "error";
            }
            $upd->close();
        } else {
            $msg = "Incorrect current password!";
            $status = "error";
        }
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
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; color: #333; }
        .topbar { position: sticky; top: 0; z-index: 500; background: #ffffff; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; border-radius: 8px; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .topbar h2 { color: #1f2937; margin: 0; font-size: 18px; }
        .topbar a { color: #007bff; font-weight: bold; text-decoration: none; font-size: 13px; }
        .card { max-width: 600px; margin: 0 auto; background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.06); }
        .card h3 { margin-top: 0; font-size: 16px; color: #1f2937; margin-bottom: 15px; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px; }
        .info-group { margin-bottom: 15px; }
        .info-label { font-size: 12px; font-weight: bold; color: #64748b; text-transform: uppercase; margin-bottom: 4px; }
        .info-value { font-size: 15px; font-weight: bold; color: #1e293b; }
        form { display: flex; flex-direction: column; gap: 12px; margin-top: 15px; }
        input[type="password"] { padding: 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
        input[type="password"]:focus { border-color: #007bff; outline: none; }
        button { background: #007bff; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; font-size: 14px; cursor: pointer; margin-top: 5px; }
        button:hover { background: #0056b3; }
        code { background: #f1f5f9; padding: 3px 6px; border-radius: 4px; color: #d926a9; }
        @media (max-width: 600px) { body { margin: 15px; } }
    </style>
</head>
<body>

<div class="topbar">
    <h2>👤 Profile Settings</h2>
    <a href="<?php echo htmlspecialchars($back_url); ?>">← Back to Dashboard</a>
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

    <form method="POST" action="profile.php?from=<?php echo htmlspecialchars($from_source); ?>">
        <label style="font-size: 13px; font-weight: bold;">Current Password:</label>
        <input type="password" name="old_password" required placeholder="Enter current password">
        
        <label style="font-size: 13px; font-weight: bold;">New Password:</label>
        <input type="password" name="new_password" required placeholder="Min 6 characters">
        
        <button type="submit" name="change_password">Update Password</button>
    </form>
</div>

</body>
</html>