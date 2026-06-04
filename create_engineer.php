<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php"); exit;
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uname  = strtolower(trim($_POST['username_id']));
    $ename  = trim($_POST['engineer_name']);
    $is_adm = intval($_POST['is_admin'] ?? 0);
    $pwd    = trim($_POST['password'] ?? '');

    if (empty($uname) || empty($ename)) {
        $error = "Username and Full Name are required.";
    } elseif (empty($pwd) || strlen($pwd) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $chk = $conn->prepare("SELECT id FROM engineers WHERE username_id=?");
        $chk->bind_param("s", $uname); $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = "Username <strong>$uname</strong> already exists.";
        } else {
            $hashed = password_hash($pwd, PASSWORD_DEFAULT);
            $ins = $conn->prepare("INSERT INTO engineers (username_id, engineer_name, password, is_admin, is_verified) VALUES (?, ?, ?, ?, 1)");
            $ins->bind_param("sssi", $uname, $ename, $hashed, $is_adm);
            $ins->execute(); $ins->close();
            header("Location: admin_engineers.php"); exit;
        }
        $chk->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Add New Engineer</title>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; color: #333; }

.header { display: flex; justify-content: space-between; align-items: center; background: #343a40; padding: 15px 20px; border-radius: 8px; color: white; flex-wrap: wrap; gap: 10px; }
.header h2 { margin: 0; font-size: 18px; }
.header a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; }

.card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.06); margin: 20px auto; max-width: 500px; }

.alert-err { background:#f8d7da; color:#721c24; padding:12px; border-radius:4px; margin-bottom:18px; border:1px solid #f5c6cb; font-size:13px; }

.form-group { margin-bottom: 18px; }
.form-group label { display: block; font-size: 13px; font-weight: 700; color: #495057; margin-bottom: 5px; }
.form-group input, .form-group select { width: 100%; height: 38px; padding: 0 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px; }
.form-group input:focus, .form-group select:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.15); }
.form-group .hint { font-size: 11px; color: #6c757d; margin-top: 4px; }

.actions { display: flex; gap: 12px; margin-top: 24px; align-items: center; }
.btn-save { background: #28a745; color: white; border: none; height: 38px; padding: 0 24px; border-radius: 4px; font-size: 14px; font-weight: bold; cursor: pointer; }
.btn-save:hover { background: #218838; }
.btn-cancel { color: #6c757d; text-decoration: none; font-size: 13px; }
</style>
</head>
<body>

<div class="header">
    <h2>+ Add New Engineer</h2>
    <a href="admin_engineers.php">← Back to Engineer Accounts</a>
</div>

<div class="card">
    <?php if ($error): ?>
        <div class="alert-err">⚠️ <?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Username <span style="color:#dc2626;">*</span></label>
            <input type="text" name="username_id" value="<?= htmlspecialchars($_POST['username_id'] ?? '') ?>" placeholder="e.g. john.doe" required>
            <span class="hint">Lowercase only. Used to log in.</span>
        </div>
        <div class="form-group">
            <label>Full Name <span style="color:#dc2626;">*</span></label>
            <input type="text" name="engineer_name" value="<?= htmlspecialchars($_POST['engineer_name'] ?? '') ?>" placeholder="e.g. John Doe" required>
        </div>
        <div class="form-group">
            <label>Password <span style="color:#dc2626;">*</span></label>
            <input type="password" name="password" placeholder="Min 6 characters" required>
            <span class="hint">The engineer can change this after their first login.</span>
        </div>
        <div class="form-group">
            <label>Role <span style="color:#dc2626;">*</span></label>
            <select name="is_admin">
                <option value="0">Engineer</option>
                <option value="1">Admin</option>
                <option value="2">Admin + Engineer</option>
            </select>
        </div>
        <div class="actions">
            <button type="submit" class="btn-save">Add Engineer</button>
            <a href="admin_engineers.php" class="btn-cancel">Cancel</a>
        </div>
    </form>
</div>

</body>
</html>