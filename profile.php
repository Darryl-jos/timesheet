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
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; }

.topbar { background: #ffffff; padding: 15px 20px; display: flex; align-items: center; justify-content: space-between; border-radius: 8px; flex-wrap: wrap; gap: 10px; }
.topbar h2 { color: #1f2937; margin: 0; font-size: 18px; }
.topbar a { color: #007bff; font-weight: bold; text-decoration: none; font-size: 13px; }

.page { max-width: 900px; margin: 20px auto; padding: 0 20px 60px; }

.section { background: white; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.06); margin-bottom: 20px; overflow: hidden; }
.section-hdr { background: #343a40; color: white; padding: 10px 20px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
.section-hdr.green { background: #155724; }
.section-body { padding: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px 20px; }
.section-body.one-col { grid-template-columns: 1fr; }

.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 12px; font-weight: 700; color: #495057; }
.form-group input { padding: 8px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; width: 100%; height: 36px; }
.form-group input:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.15); }

.info-value { font-size: 14px; font-weight: bold; color: #111; padding: 6px 0; }
.info-value.highlight { color: #28a745; }
.info-value code { background: #f8f9fa; padding: 3px 6px; border-radius: 4px; border: 1px solid #e9ecef; font-family: monospace; font-size: 13px; color: #d63384; }

.actions { display: flex; gap: 12px; margin-top: 10px; }
.btn-save { background: #28a745; color: white; border: none; padding: 0 28px; height: 40px; border-radius: 4px; font-size: 14px; font-weight: bold; cursor: pointer; }
.btn-save:hover { background: #218838; }

.alert { padding: 12px; border-radius: 4px; font-weight: bold; margin-bottom: 15px; font-size: 13px; text-align: center; }
.alert-success { color: #155724; background: #d4edda; border: 1px solid #c3e6cb; }
.alert-error { color: #721c24; background: #f8d7da; border: 1px solid #f5c6cb; }

@media (max-width: 600px) {
    body { margin: 15px; }
    .section-body { grid-template-columns: 1fr; }
    .page { padding: 0 0 40px; }
}
</style>
</head>
<body>

<div class="topbar">
    <h2>👤 My Profile Settings</h2>
    <a href="<?php echo htmlspecialchars($back_link); ?>">← Back to Dashboard</a>
</div>

<div class="page">
    <div class="section">
        <div class="section-hdr">👤 Account Information</div>
        <div class="section-body one-col">
            <div class="form-group">
                <label>Username ID</label>
                <div class="info-value"><code><?php echo htmlspecialchars($user_info['username_id']); ?></code></div>
            </div>
            
            <div class="form-group">
                <label>Assigned Username (Real Name)</label>
                <div class="info-value highlight"><?php echo htmlspecialchars($user_info['engineer_name'] ? $user_info['engineer_name'] : 'Not assigned yet'); ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-hdr green">🔒 Security & Change Password</div>
        <div class="section-body one-col">
            <?php if(!empty($msg)): ?>
                <div class="alert <?php echo $status == 'success' ? 'alert-success' : 'alert-error'; ?>">
                    <?php echo $msg; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>New Password <span style="color:#dc2626;">*</span></label>
                    <input type="password" name="new_password" required placeholder="Min 6 characters">
                </div>
                
                <div class="actions">
                    <button type="submit" name="change_password" class="btn-save">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>