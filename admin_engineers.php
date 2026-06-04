<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php"); exit;
}

$msg = "";
$msg_type = "";

// ── Delete engineer ───────────────────────────────────────────────────────────
if (isset($_GET['delete_eng'])) {
    $del_id = intval($_GET['delete_eng']);
    if ($del_id == $_SESSION['engineer_id']) {
        $msg = "You cannot delete your own account."; $msg_type = "error";
    } else {
        $stmt = $conn->prepare("DELETE FROM engineers WHERE id=?");
        $stmt->bind_param("i", $del_id); $stmt->execute(); $stmt->close();
        header("Location: admin_engineers.php"); exit;
    }
}

// ── Edit engineer ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_engineer'])) {
    $edit_id = intval($_POST['edit_id']);
    $ename   = trim($_POST['engineer_name']);
    $uname   = strtolower(trim($_POST['username_id']));
    $is_adm  = intval($_POST['is_admin'] ?? 0);
    $new_pwd = trim($_POST['password'] ?? '');

    if (empty($ename) || empty($uname)) {
        $msg = "Username and Name cannot be empty."; $msg_type = "error";
    } elseif (!empty($new_pwd) && strlen($new_pwd) < 6) {
        $msg = "Password must be at least 6 characters."; $msg_type = "error";
    } else {
        if (!empty($new_pwd)) {
            $hashed = password_hash($new_pwd, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE engineers SET username_id=?, engineer_name=?, is_admin=?, password=? WHERE id=?");
            $stmt->bind_param("ssisi", $uname, $ename, $is_adm, $hashed, $edit_id);
        } else {
            $stmt = $conn->prepare("UPDATE engineers SET username_id=?, engineer_name=?, is_admin=? WHERE id=?");
            $stmt->bind_param("ssii", $uname, $ename, $is_adm, $edit_id);
        }
        $stmt->execute(); $stmt->close();
        header("Location: admin_engineers.php?saved=1"); exit;
    }
}

// ── Edit mode ─────────────────────────────────────────────────────────────────
$edit_eng = null;
if (isset($_GET['edit_eng'])) {
    $eid = intval($_GET['edit_eng']);
    $stmt = $conn->prepare("SELECT * FROM engineers WHERE id=?");
    $stmt->bind_param("i", $eid); $stmt->execute();
    $edit_eng = $stmt->get_result()->fetch_assoc(); $stmt->close();
}

if (isset($_GET['saved'])) { $msg = "Changes saved successfully."; $msg_type = "success"; }

$eng_result = $conn->query("SELECT * FROM engineers ORDER BY is_admin DESC, engineer_name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Manage Engineers</title>
<style>
    .is-hidden { display: none !important; }
    body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; color: #333; }
    .header { display: flex; justify-content: space-between; align-items: center; background: #343a40; padding: 15px 20px; border-radius: 8px; color: white; flex-wrap: wrap; gap: 10px; }
    .header h2 { margin: 0; font-size: 18px; }
    .header a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; }
    .card { background: white; padding: 20px 25px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-top: 20px; }
    .alert { padding: 11px 15px; border-radius: 4px; margin-bottom: 15px; font-size: 13px; }
    .alert.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .alert.error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

    /* Edit form */
    .edit-form-wrap { background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef; margin-bottom: 20px; }
    .edit-form-wrap h4 { margin: 0 0 12px; font-size: 14px; color: #343a40; }
    .form-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
    .form-row .fg { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 130px; }
    .form-row label { font-size: 12px; font-weight: 700; color: #495057; }
    .form-row input, .form-row select { height: 36px; padding: 0 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; width: 100%; }
    .btn-save { background: #ffc107; color: #333; border: none; height: 36px; padding: 0 20px; border-radius: 4px; font-size: 13px; font-weight: bold; cursor: pointer; white-space: nowrap; }
    .btn-save:hover { background: #e0a800; }
    .btn-cancel-link { color: #6c757d; text-decoration: none; font-size: 13px; align-self: center; white-space: nowrap; }

    /* Toolbar */
    .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
    .search-input { flex: 1; height: 36px; padding: 0 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; min-width: 200px; }
    .btn-add { background: #28a745; color: white; text-decoration: none; height: 36px; padding: 0 18px; border-radius: 4px; font-size: 13px; font-weight: bold; display: inline-flex; align-items: center; white-space: nowrap; }
    .btn-add:hover { background: #218838; }

    /* Table */
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px 12px; border-bottom: 1px solid #dee2e6; text-align: left; font-size: 13px; }
    th { background: #f8f9fa; font-weight: bold; color: #495057; }
    tbody tr:hover { background: #f8faff; }
    td:last-child { white-space: nowrap; }

    .role-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
    .role-admin { background: #fff3cd; color: #856404; }
    .role-user  { background: #d1ecf1; color: #0c5460; }

    .btn-edit { background: #ffc107; color: #333; padding: 4px 10px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; margin-right: 4px; }
    .btn-del  { background: #dc3545; color: white; padding: 4px 10px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; }
</style>
</head>
<body>

<div class="header">
    <h2>👷 Engineer Accounts</h2>
    <a href="admin.php">← Back to Admin</a>
</div>

<div class="card">
    <?php if ($msg): ?>
        <div class="alert <?= $msg_type ?>"><?= $msg_type==='success'?'✅':'⚠️' ?> <?= $msg ?></div>
    <?php endif; ?>

    <!-- Edit form — only shown when editing -->
    <?php if ($edit_eng): ?>
    <div class="edit-form-wrap">
        <h4>✏️ Editing: <?= htmlspecialchars($edit_eng['engineer_name']) ?></h4>
        <form method="POST">
            <input type="hidden" name="edit_id" value="<?= $edit_eng['id'] ?>">
            <div class="form-row">
                <div class="fg">
                    <label>Username</label>
                    <input type="text" name="username_id" value="<?= htmlspecialchars($edit_eng['username_id']) ?>" required>
                </div>
                <div class="fg">
                    <label>Full Name</label>
                    <input type="text" name="engineer_name" value="<?= htmlspecialchars($edit_eng['engineer_name']) ?>" required>
                </div>
                <div class="fg">
                    <label>New Password <span style="font-weight:400;color:#9ca3af;">(blank = keep current)</span></label>
                    <input type="password" name="password" placeholder="Leave blank to keep unchanged">
                </div>
                <div class="fg" style="max-width:160px;">
                    <label>Role</label>
                    <select name="is_admin">
                        <option value="0" <?= $edit_eng['is_admin']==0?'selected':'' ?>>Engineer</option>
                        <option value="1" <?= $edit_eng['is_admin']==1?'selected':'' ?>>Admin</option>
                        <option value="2" <?= $edit_eng['is_admin']==2?'selected':'' ?>>Admin + Engineer</option>
                    </select>
                </div>
                <button type="submit" name="edit_engineer" class="btn-save">Save Changes</button>
                <a href="admin_engineers.php" class="btn-cancel-link">Cancel</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Toolbar: Search + Add Engineer -->
    <div class="toolbar">
        <input type="text" class="search-input" id="eng-search" placeholder="🔍 Search by name or username...">
        <a href="create_engineer.php" class="btn-add">+ Add Engineer</a>
    </div>

    <!-- Table -->
    <table id="eng-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Username</th>
                <th>Full Name</th>
                <th>Role</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php $i=1; while ($e = $eng_result->fetch_assoc()): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><code><?= htmlspecialchars($e['username_id']) ?></code></td>
                <td><strong><?= htmlspecialchars($e['engineer_name']) ?></strong></td>
                <td>
                    <?php
                    $role = $e['is_admin']==2 ? 'Admin+Eng' : ($e['is_admin']==1 ? 'Admin' : 'Engineer');
                    $cls  = $e['is_admin'] ? 'role-admin' : 'role-user';
                    ?>
                    <span class="role-badge <?= $cls ?>"><?= $role ?></span>
                </td>
                <td>
                    <a href="admin_engineers.php?edit_eng=<?= $e['id'] ?>" class="btn-edit">Edit</a>
                    <?php if ($e['id'] != $_SESSION['engineer_id']): ?>
                    <a href="admin_engineers.php?delete_eng=<?= $e['id'] ?>" class="btn-del"
                       onclick="return confirm('Delete <?= htmlspecialchars(addslashes($e['engineer_name'])) ?>?\nThis cannot be undone.')">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script>
document.getElementById('eng-search').addEventListener('input', function() {
    const f = this.value.toLowerCase();
    document.querySelectorAll('#eng-table tbody tr').forEach(tr => {
        tr.classList.toggle('is-hidden', !!f && !tr.textContent.toLowerCase().includes(f));
    });
});
</script>
</body>
</html>