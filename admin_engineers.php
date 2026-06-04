<?php
require_once 'config.php';

if (!isset($_SESSION['engineer_id']) || !isset($_SESSION['is_admin']) || ($_SESSION['is_admin'] != 1 && $_SESSION['is_admin'] != 2)) {
    header("Location: login.php"); exit;
}

$msg = "";
$msg_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_engineer'])) {
    $uname  = strtolower(trim($_POST['username_id']));
    $ename  = trim($_POST['engineer_name']);
    $is_adm = isset($_POST['is_admin']) ? 1 : 0;
    $pwd    = trim($_POST['password'] ?? '');

    if (empty($uname) || empty($ename)) {
        $msg = "Username and Full Name are required."; $msg_type = "error";
    } elseif (empty($pwd) || strlen($pwd) < 6) {
        $msg = "Password must be at least 6 characters."; $msg_type = "error";
    } else {
        $chk = $conn->prepare("SELECT id FROM engineers WHERE username_id=?");
        $chk->bind_param("s", $uname); $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $msg = "Username <strong>$uname</strong> already exists."; $msg_type = "error";
        } else {
            $hashed = password_hash($pwd, PASSWORD_DEFAULT);
            $ins = $conn->prepare("INSERT INTO engineers (username_id, engineer_name, password, is_admin, is_verified) VALUES (?, ?, ?, ?, 1)");
            $ins->bind_param("sssi", $uname, $ename, $hashed, $is_adm); $ins->execute(); $ins->close();
            $msg = "Engineer <strong>$ename</strong> added successfully."; $msg_type = "success";
        }
        $chk->close();
    }
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_engineer'])) {
    $edit_id = intval($_POST['edit_id']);
    $ename   = trim($_POST['engineer_name']);
    $uname   = strtolower(trim($_POST['username_id']));
    $is_adm  = isset($_POST['is_admin']) ? 1 : 0;
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

$edit_eng = null;
$show_add = isset($_GET['add']);
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
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 30px; background: #f4f7f6; color: #333; }

    .page { box-sizing: border-box; }

    .page-header { display: flex; justify-content: space-between; align-items: center; background: #343a40; padding: 15px 20px; border-radius: 8px; color: white; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
    .page-header h2 { margin: 0; font-size: 18px; }
    .header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .header-actions a { color: #ffc107; font-weight: bold; text-decoration: none; font-size: 13px; }

    .card { background: white; padding: 16px 18px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 14px; }

    .alert { padding: 11px 15px; border-radius: 4px; margin-bottom: 14px; font-size: 13px; }
    .alert.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .alert.error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

    .form-wrap { background: #f8f9fa; padding: 14px; border-radius: 6px; border: 1px solid #e9ecef; margin-bottom: 14px; }
    .form-wrap h4 { margin: 0 0 12px; font-size: 14px; color: #343a40; font-weight: 700; }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; }
    .fg { display: flex; flex-direction: column; gap: 4px; }
    .fg label { font-size: 12px; font-weight: 700; color: #495057; }
    .fg input { height: 42px; padding: 0 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; width: 100%; }
    .fg input:focus { border-color: #007bff; outline: none; box-shadow: 0 0 0 2px rgba(0,123,255,.15); }
    
    .pwd-wrap { position: relative; }
    .pwd-wrap input { padding-right: 42px; }
    .pwd-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; font-size: 16px; color: #6c757d; padding: 0; line-height: 1; }
    
    .form-bottom { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding-top: 4px; }
    .admin-check { display: flex; align-items: center; gap: 8px; }
    .admin-check input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; flex-shrink: 0; }
    .admin-check label { font-size: 13px; font-weight: 600; cursor: pointer; margin: 0; }
    .form-actions { display: flex; gap: 10px; align-items: center; margin-left: auto; }
    .btn-save { background: #28a745; color: white; border: none; height: 42px; padding: 0 22px; border-radius: 4px; font-size: 14px; font-weight: bold; cursor: pointer; }
    .btn-cancel-link { color: #6c757d; text-decoration: none; font-size: 13px; }

    .toolbar { display: flex; gap: 10px; margin-bottom: 14px; }
    .search-input { flex: 1; height: 42px; padding: 0 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
    .btn-add { background: #28a745; color: white; text-decoration: none; height: 42px; padding: 0 16px; border-radius: 4px; font-size: 13px; font-weight: bold; display: inline-flex; align-items: center; white-space: nowrap; border: none; cursor: pointer; flex-shrink: 0; }

    .tbl-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px 12px; border-bottom: 1px solid #dee2e6; text-align: left; font-size: 13px; white-space: nowrap; }
    th { background: #f8f9fa; font-weight: bold; color: #495057; }
    tbody tr:hover { background: #f8faff; }
    .role-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
    .role-admin { background: #fff3cd; color: #856404; }
    .role-user  { background: #d1ecf1; color: #0c5460; }
    .btn-edit { background: #ffc107; color: #333; padding: 5px 12px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; margin-right: 4px; display: inline-block; }
    .btn-del  { background: #dc3545; color: white; padding: 5px 12px; text-decoration: none; border-radius: 4px; font-size: 12px; font-weight: bold; display: inline-block; }

    .mobile-list { display: none; }
    .eng-card { background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); padding: 14px 16px; margin-bottom: 10px; border-left: 4px solid #dee2e6; }
    .eng-card.is-admin-card { border-left-color: #ffc107; }
    .eng-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
    .eng-card-name { font-weight: 700; font-size: 15px; color: #1e2330; }
    .eng-card-username { font-size: 12px; color: #64748b; margin-top: 2px; }
    .eng-card-actions { display: flex; gap: 6px; flex-shrink: 0; }
    .eng-card-actions .btn-edit,
    .eng-card-actions .btn-del { padding: 6px 14px; font-size: 13px; margin: 0; }

    @media (max-width: 600px) {
        body { margin: 15px; }
        .page-header { padding: 12px 14px; }
        .page-header h2 { font-size: 16px; }
        .form-grid { grid-template-columns: 1fr; }
        .form-actions { margin-left: 0; width: 100%; }
        .btn-save { width: 100%; justify-content: center; }
        .form-bottom { flex-direction: column; align-items: flex-start; gap: 10px; }
        .tbl-wrap { display: none; }
        .mobile-list { display: block; }
    }
    @media (min-width: 601px) and (max-width: 900px) {
        .form-grid { grid-template-columns: 1fr 1fr; }
    }
</style>
</head>
<body>

<div class="page">
    <div class="page-header">
        <h2>👷 Engineer Accounts</h2>
        <div class="header-actions">
            <a href="admin.php">← Back to Admin</a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert <?= $msg_type ?>"><?= $msg_type==='success'?'✅':'⚠️' ?> <?= $msg ?></div>
    <?php endif; ?>

    <?php if ($show_add && !$edit_eng): ?>
    <div class="form-wrap">
        <h4>+ Add New Engineer</h4>
        <form method="POST" onsubmit="return checkPwd('add')">
            <div class="form-grid">
                <div class="fg">
                    <label>Username</label>
                    <input type="text" name="username_id" placeholder="e.g. john.doe" required autocomplete="off">
                </div>
                <div class="fg">
                    <label>Full Name</label>
                    <input type="text" name="engineer_name" placeholder="e.g. John Doe" required>
                </div>
                <div class="fg">
                    <label>Password</label>
                    <div class="pwd-wrap">
                        <input type="password" name="password" id="add_pwd" placeholder="Min 6 characters" required>
                        <button type="button" class="pwd-toggle" onclick="togglePwd('add_pwd', this)">👁</button>
                    </div>
                </div>
                <div class="fg">
                    <label>Confirm Password</label>
                    <div class="pwd-wrap">
                        <input type="password" id="add_pwd2" placeholder="Re-enter password" required>
                        <button type="button" class="pwd-toggle" onclick="togglePwd('add_pwd2', this)">👁</button>
                    </div>
                    <span id="add_pwd_err" style="font-size:11px;color:#dc3545;display:none;">Passwords do not match</span>
                </div>
            </div>
            <div class="form-bottom">
                <div class="admin-check">
                    <input type="checkbox" name="is_admin" value="1" id="add_is_admin">
                    <label for="add_is_admin">Grant Admin Access</label>
                </div>
                <div class="form-actions">
                    <button type="submit" name="add_engineer" class="btn-save">Add Engineer</button>
                    <a href="admin_engineers.php" class="btn-cancel-link">Cancel</a>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($edit_eng): ?>
    <div class="form-wrap">
        <h4>✏️ Editing: <?= htmlspecialchars($edit_eng['engineer_name']) ?></h4>
        <form method="POST" onsubmit="return checkPwd('edit')">
            <input type="hidden" name="edit_id" value="<?= $edit_eng['id'] ?>">
            <div class="form-grid">
                <div class="fg">
                    <label>Username</label>
                    <input type="text" name="username_id" value="<?= htmlspecialchars($edit_eng['username_id']) ?>" required>
                </div>
                <div class="fg">
                    <label>Full Name</label>
                    <input type="text" name="engineer_name" value="<?= htmlspecialchars($edit_eng['engineer_name']) ?>" required>
                </div>
                <div class="fg">
                    <label>New Password <span style="font-weight:400;color:#9ca3af;font-size:11px;">(blank = keep current)</span></label>
                    <div class="pwd-wrap">
                        <input type="password" name="password" id="edit_pwd" placeholder="Leave blank to keep unchanged">
                        <button type="button" class="pwd-toggle" onclick="togglePwd('edit_pwd', this)">👁</button>
                    </div>
                </div>
                <div class="fg">
                    <label>Confirm New Password</label>
                    <div class="pwd-wrap">
                        <input type="password" id="edit_pwd2" placeholder="Re-enter new password">
                        <button type="button" class="pwd-toggle" onclick="togglePwd('edit_pwd2', this)">👁</button>
                    </div>
                    <span id="edit_pwd_err" style="font-size:11px;color:#dc3545;display:none;">Passwords do not match</span>
                </div>
            </div>
            <div class="form-bottom">
                <div class="admin-check">
                    <input type="checkbox" name="is_admin" value="1" id="edit_is_admin" <?= $edit_eng['is_admin'] >= 1 ? 'checked' : '' ?>>
                    <label for="edit_is_admin">Grant Admin Access</label>
                </div>
                <div class="form-actions">
                    <button type="submit" name="edit_engineer" class="btn-save">Save Changes</button>
                    <a href="admin_engineers.php" class="btn-cancel-link">Cancel</a>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="toolbar">
        <input type="text" class="search-input" id="eng-search" placeholder="🔍 Search name or username...">
        <a href="admin_engineers.php?add=1" class="btn-add">+ Add Engineer</a>
    </div>

    <div class="tbl-wrap">
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
        <?php
        $i = 1;
        $eng_rows = [];
        while ($e = $eng_result->fetch_assoc()) { $eng_rows[] = $e; }
        foreach ($eng_rows as $e):
        ?>
            <tr data-name="<?= strtolower(htmlspecialchars($e['engineer_name'])) ?>" data-user="<?= strtolower(htmlspecialchars($e['username_id'])) ?>">
                <td><?= $i++ ?></td>
                <td><code><?= htmlspecialchars($e['username_id']) ?></code></td>
                <td><strong><?= htmlspecialchars($e['engineer_name']) ?></strong></td>
                <td><span class="role-badge <?= $e['is_admin']>=1?'role-admin':'role-user' ?>"><?= $e['is_admin']>=1?'Admin':'Engineer' ?></span></td>
                <td>
                    <a href="admin_engineers.php?edit_eng=<?= $e['id'] ?>" class="btn-edit">Edit</a>
                    <?php if ($e['id'] != $_SESSION['engineer_id']): ?>
                    <a href="admin_engineers.php?delete_eng=<?= $e['id'] ?>" class="btn-del"
                       onclick="return confirm('Delete <?= htmlspecialchars(addslashes($e['engineer_name'])) ?>?')">Delete</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="mobile-list" id="mobile-list">
    <?php foreach ($eng_rows as $e): ?>
        <div class="eng-card <?= $e['is_admin']>=1?'is-admin-card':'' ?>"
             data-name="<?= strtolower(htmlspecialchars($e['engineer_name'])) ?>"
             data-user="<?= strtolower(htmlspecialchars($e['username_id'])) ?>">
            <div class="eng-card-top">
                <div>
                    <div class="eng-card-name"><?= htmlspecialchars($e['engineer_name']) ?></div>
                    <div class="eng-card-username">@<?= htmlspecialchars($e['username_id']) ?> &nbsp;·&nbsp;
                        <span class="role-badge <?= $e['is_admin']>=1?'role-admin':'role-user' ?>"><?= $e['is_admin']>=1?'Admin':'Engineer' ?></span>
                    </div>
                </div>
                <div class="eng-card-actions">
                    <a href="admin_engineers.php?edit_eng=<?= $e['id'] ?>" class="btn-edit">Edit</a>
                    <?php if ($e['id'] != $_SESSION['engineer_id']): ?>
                    <a href="admin_engineers.php?delete_eng=<?= $e['id'] ?>" class="btn-del"
                       onclick="return confirm('Delete <?= htmlspecialchars(addslashes($e['engineer_name'])) ?>?')">Delete</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

</div>

<script>
document.getElementById('eng-search').addEventListener('input', function() {
    const f = this.value.toLowerCase();
    document.querySelectorAll('#eng-table tbody tr').forEach(tr => {
        const match = !f || tr.dataset.name.includes(f) || tr.dataset.user.includes(f);
        tr.classList.toggle('is-hidden', !match);
    });
    document.querySelectorAll('#mobile-list .eng-card').forEach(card => {
        const match = !f || card.dataset.name.includes(f) || card.dataset.user.includes(f);
        card.classList.toggle('is-hidden', !match);
    });
});

function togglePwd(id, btn) {
    const inp = document.getElementById(id);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    btn.textContent = inp.type === 'password' ? '👁' : '🙈';
}

function checkPwd(prefix) {
    const pwd  = document.getElementById(prefix + '_pwd');
    const pwd2 = document.getElementById(prefix + '_pwd2');
    const err  = document.getElementById(prefix + '_pwd_err');
    if (prefix === 'edit' && !pwd.value && !pwd2.value) return true;
    if (pwd.value !== pwd2.value) {
        err.style.display = 'block';
        pwd2.focus();
        return false;
    }
    err.style.display = 'none';
    return true;
}

['add_pwd','add_pwd2','edit_pwd','edit_pwd2'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', () => {
        const prefix = id.startsWith('add') ? 'add' : 'edit';
        document.getElementById(prefix + '_pwd_err').style.display = 'none';
    });
});
</script>
</body>
</html>